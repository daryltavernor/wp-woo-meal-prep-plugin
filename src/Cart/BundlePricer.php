<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\BundleMeta;
use WC_Cart;

/**
 * Owns line-price mutation for meal cart items.
 *
 * Pipeline invariant (read CHECKOUT_PRICING.md for the full design):
 *
 *   unit_price = ( bundle_applies ? bundle_effective_unit : catalog_base )
 *              + selection_delta   // ingredient swaps + add-on prices
 *
 * Every value passed to set_price() is derived from canonical sources only —
 * the catalog price (re-read via wc_get_product() per pass, NOT from the
 * mutated $item['data']), the persisted Selections meta, and the bundle tier
 * config. None of these inputs change as a result of calculate_totals()
 * running, so the pipeline is idempotent: running apply() N times produces
 * identical totals.
 *
 * Composition note: third-party pricing plugins that hook
 * woocommerce_before_calculate_totals on the same item are not composed with;
 * the unified pricer is deliberately the single owner of cart-line price
 * mutation for meals.
 */
final class BundlePricer {

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply' ], 10 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'render_notice' ], 20, 2 );
	}

	public function apply( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$groups        = [];
		$catalog_cache = [];
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ Selections::CART_KEY ] ) || empty( $item['data'] ) ) {
				continue;
			}
			$pid                       = (int) $item['product_id'];
			$groups[ $pid ][ $key ]    = $item;
			if ( ! isset( $catalog_cache[ $pid ] ) ) {
				$product                = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$catalog_cache[ $pid ]  = $product ? (float) $product->get_price( 'edit' ) : 0.0;
			}
		}

		foreach ( $groups as $product_id => $items ) {
			$catalog_base = $catalog_cache[ $product_id ];
			$bundles      = BundleMeta::get_bundles( $product_id );

			$total_qty = 0;
			foreach ( $items as $item ) {
				$total_qty += (int) $item['quantity'];
			}

			$result         = self::calculate( $total_qty, $bundles );
			$bundle_applied = ! empty( $result['applied'] );

			if ( ! $bundle_applied ) {
				foreach ( $items as $key => $item ) {
					$delta      = Selections::compute_price_delta( $product_id, $item[ Selections::CART_KEY ] ?? [] );
					$line_price = max( 0.0, $catalog_base + $delta );
					$item['data']->set_price( $line_price );
					$cart->cart_contents[ $key ]['fn_bundle'] = null;
				}
				continue;
			}

			// Bundle applies. The bundle total covers the meal BASE for every
			// unit in the group; per-line add-on deltas are charged on top.
			//
			// We cannot simply price every unit at total/qty: WooCommerce
			// rounds each line's (unit_price × qty) to the store's currency
			// precision INDEPENDENTLY, so the sum of those rounded line totals
			// drifts off the exact bundle total. Example: 15 meals at £50 split
			// across three 5-meal lines prices each unit at £3.3333…, and WC
			// rounds each line to £16.67, summing to £50.01 instead of £50.00.
			//
			// Instead we apportion the bundle total across the lines in integer
			// pence and hand the rounding remainder to the final line, so the
			// per-line totals always sum to exactly the bundle total. Because
			// (meal_line_total + delta × qty) is penny-clean for each line, WC's
			// multiply-then-round introduces no further drift.
			$total_pence = (int) round( (float) $result['total'] * 100 );
			$assigned    = 0;
			$line_count  = count( $items );
			$index       = 0;

			foreach ( $items as $key => $item ) {
				++$index;
				$qty = (int) $item['quantity'];

				if ( $index === $line_count ) {
					// Final line absorbs whatever pence remain so the group
					// sums to the bundle total exactly.
					$meal_pence = $total_pence - $assigned;
				} else {
					$meal_pence = $total_qty > 0 ? (int) round( $total_pence * $qty / $total_qty ) : 0;
					$assigned  += $meal_pence;
				}

				$delta           = Selections::compute_price_delta( $product_id, $item[ Selections::CART_KEY ] ?? [] );
				$meal_line_total = $meal_pence / 100;
				$unit_price      = $qty > 0 ? max( 0.0, ( $meal_line_total / $qty ) + $delta ) : 0.0;
				$item['data']->set_price( $unit_price );

				$cart->cart_contents[ $key ]['fn_bundle'] = [
					'applied_tier'    => $result['tier'],
					'effective_unit'  => $result['effective_unit'],
					'bundle_units'    => $total_qty,
					'remainder_units' => 0,
					'bundle_total'    => $result['total'],
					'per_meal_rate'   => $result['per_meal_rate'],
					'threshold_qty'   => $result['threshold_qty'],
					'extra_qty'       => $result['extra_qty'],
					'bundle_price'    => $result['bundle_price'],
				];
			}
		}
	}

	/**
	 * Pricing model:
	 * - Find the LARGEST tier whose qty threshold is <= total cart qty.
	 * - At exactly the threshold the customer pays the published bundle price.
	 * - Above the threshold each extra meal is charged at the tier's per-meal rate,
	 *   which is bundle_price / threshold_qty rounded UP to the nearest penny so
	 *   the customer always sees a clean number.
	 * - Below the lowest threshold no bundle applies (catalog rate kept).
	 *
	 * Public + static so the upsell logic in TotalsDisplay can reuse it
	 * without duplicating the math.
	 *
	 * @param int   $qty     Total cart qty for the product.
	 * @param array $bundles Array of [ 'qty' => int, 'price' => float ] tiers.
	 * @return array{applied: bool, tier?: array, threshold_qty?: int, bundle_price?: float, extra_qty?: int, per_meal_rate?: float, total?: float, effective_unit?: float}
	 */
	public static function calculate( int $qty, array $bundles ): array {
		$applicable_tier = null;
		foreach ( $bundles as $tier ) {
			$tier_qty   = (int) ( $tier['qty'] ?? 0 );
			$tier_price = (float) ( $tier['price'] ?? 0 );
			if ( $tier_qty <= 0 || $tier_price <= 0 ) {
				continue;
			}
			if ( $qty < $tier_qty ) {
				continue;
			}
			if ( null === $applicable_tier || $tier_qty > (int) $applicable_tier['qty'] ) {
				$applicable_tier = $tier;
			}
		}

		if ( null === $applicable_tier ) {
			return [ 'applied' => false ];
		}

		$threshold_qty = (int) $applicable_tier['qty'];
		$bundle_price  = (float) $applicable_tier['price'];
		$extra_qty     = $qty - $threshold_qty;

		// Use integer pence to avoid float-precision artefacts when computing the
		// per-meal rate. ceil(bundle_price_pence / threshold_qty) gives the
		// rounded-up per-meal rate in pence.
		$bundle_price_pence = (int) round( $bundle_price * 100 );
		$rate_pence         = (int) ceil( $bundle_price_pence / $threshold_qty );
		$per_meal_rate      = $rate_pence / 100;

		$total          = $bundle_price + ( $extra_qty * $per_meal_rate );
		$effective_unit = $qty > 0 ? $total / $qty : 0.0;

		return [
			'applied'        => true,
			'tier'           => $applicable_tier,
			'threshold_qty'  => $threshold_qty,
			'bundle_price'   => $bundle_price,
			'extra_qty'      => $extra_qty,
			'per_meal_rate'  => $per_meal_rate,
			'total'          => $total,
			'effective_unit' => $effective_unit,
		];
	}

	public function render_notice( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['fn_bundle'] ) || empty( $cart_item['fn_bundle']['applied_tier'] ) ) {
			return $item_data;
		}
		$bundle    = $cart_item['fn_bundle'];
		$tier      = $bundle['applied_tier'];
		$rate      = wc_price( (float) ( $bundle['per_meal_rate'] ?? $bundle['effective_unit'] ) );
		$has_extra = (int) ( $bundle['extra_qty'] ?? 0 ) > 0;

		if ( $has_extra ) {
			$item_data[] = [
				'key'   => __( 'Bundle', 'fastnutrition-mealprep' ),
				'value' => sprintf(
					/* translators: 1: tier qty, 2: tier price, 3: per-meal rate */
					esc_html__( '%1$d for %2$s, extras at %3$s each (add-ons extra)', 'fastnutrition-mealprep' ),
					(int) $tier['qty'],
					wc_price( (float) $tier['price'] ),
					$rate
				),
			];
		} else {
			$item_data[] = [
				'key'   => __( 'Bundle', 'fastnutrition-mealprep' ),
				'value' => sprintf(
					/* translators: 1: tier qty, 2: tier price, 3: effective per-meal rate */
					esc_html__( '%1$d for %2$s (~%3$s each, add-ons extra)', 'fastnutrition-mealprep' ),
					(int) $tier['qty'],
					wc_price( (float) $tier['price'] ),
					$rate
				),
			];
		}

		return $item_data;
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\BundleMeta;
use WC_Cart;

final class BundlePricer {

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply' ], 20 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'render_notice' ], 20, 2 );
	}

	public function apply( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$groups = [];
		foreach ( $cart->get_cart() as $key => $item ) {
			$groups[ (int) $item['product_id'] ][ $key ] = $item;
		}

		foreach ( $groups as $product_id => $items ) {
			$bundles = BundleMeta::get_bundles( $product_id );
			if ( empty( $bundles ) ) {
				continue;
			}

			$total_qty = 0;
			foreach ( $items as $item ) {
				$total_qty += (int) $item['quantity'];
			}

			$result = self::calculate( $total_qty, $bundles );
			if ( ! $result['applied'] ) {
				// Below the lowest threshold — clear any prior override and leave AddOnPricer's price alone.
				foreach ( $items as $key => $item ) {
					$cart->cart_contents[ $key ]['fn_bundle'] = null;
				}
				continue;
			}

			$effective_unit = $result['effective_unit'];

			foreach ( $items as $key => $item ) {
				$delta      = Selections::compute_price_delta( $product_id, $item[ Selections::CART_KEY ] ?? [] );
				$line_price = $effective_unit + $delta;
				$item['data']->set_price( max( 0, $line_price ) );
				$cart->cart_contents[ $key ]['fn_bundle'] = [
					'applied_tier'    => $result['tier'],
					'effective_unit'  => $effective_unit,
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

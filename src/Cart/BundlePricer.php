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

		$items_by_group = [];
		$catalog_cache  = [];
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ Selections::CART_KEY ] ) || empty( $item['data'] ) ) {
				continue;
			}
			$pid                            = (int) $item['product_id'];
			$items_by_group[ $pid ][ $key ] = $item;
			if ( ! isset( $catalog_cache[ $pid ] ) ) {
				$product               = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$catalog_cache[ $pid ] = $product ? (float) $product->get_price( 'edit' ) : 0.0;
			}
		}

		foreach ( $items_by_group as $product_id => $items ) {
			$bundles = BundleMeta::get_bundles( $product_id );

			// Build the per-line group (quantity + canonical selection delta) and
			// delegate the bundle apportionment to the shared MealPricing core so
			// the cart and the offline order builder price meals identically.
			$group = [];
			foreach ( $items as $key => $item ) {
				$group[ $key ] = [
					'quantity' => (int) $item['quantity'],
					'delta'    => Selections::compute_price_delta( $product_id, $item[ Selections::CART_KEY ] ?? [] ),
				];
			}

			$priced = MealPricing::price_product_group( $catalog_cache[ $product_id ], $bundles, $group );

			foreach ( $items as $key => $item ) {
				$res = $priced[ $key ];
				$item['data']->set_price( (float) $res['unit_price'] );
				$cart->cart_contents[ $key ]['fn_bundle'] = $res['bundle'];
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

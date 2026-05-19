<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\BundleMeta;
use WC_Cart;

final class BundlePricer {

	public function register(): void {
		// Run AFTER AddOnPricer (priority 10) so we can override the base portion
		// with the bundle price while preserving each line's selection delta.
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

			$applied = $this->allocate( $total_qty, $bundles );
			if ( empty( $applied['units'] ) ) {
				// No bundle threshold met — clear any prior override flag, leave AddOnPricer's price alone.
				foreach ( $items as $key => $item ) {
					$cart->cart_contents[ $key ]['fn_bundle'] = null;
				}
				continue;
			}

			$bundle_price_total = $applied['total'];
			$bundled_qty        = $applied['units'];
			$remainder_qty      = $total_qty - $bundled_qty;

			// Use the unmodified catalog price as the BASE — AddOnPricer has already
			// added the per-line delta to each cart item's price, so we read the
			// catalog separately to avoid double-counting it.
			$catalog_product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
			$base_price = $catalog_product ? (float) $catalog_product->get_price( 'edit' ) : 0.0;

			$remainder_total = $remainder_qty * $base_price;
			$effective_unit  = $total_qty > 0 ? ( $bundle_price_total + $remainder_total ) / $total_qty : $base_price;

			foreach ( $items as $key => $item ) {
				$delta      = Selections::compute_price_delta( $product_id, $item[ Selections::CART_KEY ] ?? [] );
				$line_price = $effective_unit + $delta;
				$item['data']->set_price( max( 0, $line_price ) );
				$cart->cart_contents[ $key ]['fn_bundle'] = [
					'applied_tier'    => $applied['tier'],
					'effective_unit'  => $effective_unit,
					'bundle_units'    => $bundled_qty,
					'remainder_units' => $remainder_qty,
					'bundle_total'    => $bundle_price_total,
				];
			}
		}
	}

	private function allocate( int $qty, array $bundles ): array {
		$total_units = 0;
		$total_price = 0.0;
		$last_tier   = null;
		foreach ( $bundles as $tier ) {
			$tier_qty = (int) ( $tier['qty'] ?? 0 );
			$tier_price = (float) ( $tier['price'] ?? 0 );
			if ( $tier_qty <= 0 || $tier_price <= 0 ) {
				continue;
			}
			while ( $qty - $total_units >= $tier_qty ) {
				$total_units += $tier_qty;
				$total_price += $tier_price;
				$last_tier    = $tier;
			}
		}
		return [
			'units' => $total_units,
			'total' => $total_price,
			'tier'  => $last_tier,
		];
	}

	public function render_notice( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['fn_bundle'] ) || empty( $cart_item['fn_bundle']['applied_tier'] ) ) {
			return $item_data;
		}
		$tier      = $cart_item['fn_bundle']['applied_tier'];
		$effective = wc_price( (float) $cart_item['fn_bundle']['effective_unit'] );
		$item_data[] = [
			'key'   => __( 'Bundle', 'fastnutrition-mealprep' ),
			'value' => sprintf(
				/* translators: 1: bundle qty, 2: bundle price, 3: effective per-meal price */
				esc_html__( '%1$d for %2$s (~%3$s each, add-ons extra)', 'fastnutrition-mealprep' ),
				(int) $tier['qty'],
				wc_price( (float) $tier['price'] ),
				$effective
			),
		];
		return $item_data;
	}
}

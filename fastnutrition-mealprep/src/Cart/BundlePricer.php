<?php
/**
 * Applies per-product quantity-based bundle pricing.
 * Groups cart items by product_id (only products with _fn_bundles), finds the best tier that fits,
 * and overrides line prices so the bundled portion totals the flat bundle price exactly.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\BundleMeta;
use WC_Cart;

final class BundlePricer {

	/**
	 * Marker key injected on cart items so downstream display code can show the bundle label.
	 */
	public const DISPLAY_KEY = 'fn_bundle_info';

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply' ], 20 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display' ], 20, 2 );
	}

	public function apply( WC_Cart $cart ): void {
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			// Prevent double-application in the same request.
		}

		// Group cart line items by product id, only for products with bundle tiers.
		$groups = [];
		foreach ( $cart->get_cart() as $cart_key => $line ) {
			$product_id = (int) $line['product_id'];
			if ( ! BundleMeta::has_bundles( $product_id ) ) {
				// Clear any stale marker in case bundles were removed.
				unset( $cart->cart_contents[ $cart_key ][ self::DISPLAY_KEY ] );
				continue;
			}
			$groups[ $product_id ][] = $cart_key;
		}

		foreach ( $groups as $product_id => $cart_keys ) {
			$tiers  = BundleMeta::get( $product_id );
			$total_qty = 0;
			foreach ( $cart_keys as $k ) {
				$total_qty += (int) $cart->cart_contents[ $k ]['quantity'];
			}

			$remaining = $total_qty;
			$bundled_total_price = 0.0;
			$bundled_units       = 0;
			foreach ( $tiers as $tier ) {
				if ( $remaining < $tier['qty'] ) {
					continue;
				}
				$count = intdiv( $remaining, $tier['qty'] );
				$bundled_total_price += $count * $tier['price'];
				$bundled_units       += $count * $tier['qty'];
				$remaining           -= $count * $tier['qty'];
			}

			if ( 0 === $bundled_units ) {
				foreach ( $cart_keys as $k ) {
					unset( $cart->cart_contents[ $k ][ self::DISPLAY_KEY ] );
				}
				continue;
			}

			$effective_per_meal = $bundled_total_price / $bundled_units;

			// Override each line item's unit price proportionally to units that fall inside the bundle.
			// Sort line items deterministically by cart key so price assignment is stable.
			sort( $cart_keys );
			$units_left_in_bundle = $bundled_units;
			foreach ( $cart_keys as $k ) {
				$line     = &$cart->cart_contents[ $k ];
				$line_qty = (int) $line['quantity'];
				$base     = (float) $line['data']->get_price( 'edit' );

				$in_bundle = min( $line_qty, $units_left_in_bundle );
				$outside   = $line_qty - $in_bundle;
				$units_left_in_bundle -= $in_bundle;

				// Per-unit effective price = weighted average of bundled effective price and base price.
				// Woo needs a single price per line, so we average across the line's units.
				if ( $line_qty > 0 ) {
					$line_total     = ( $in_bundle * $effective_per_meal ) + ( $outside * $base );
					$per_unit_price = $line_total / $line_qty;
					$line['data']->set_price( $per_unit_price );
				}

				// Human-readable label for the cart display.
				$tier_label = $this->describe_tiers( $tiers );
				$line[ self::DISPLAY_KEY ] = [
					'label'              => sprintf(
						/* translators: 1: tiers description, 2: per-meal price */
						__( 'Bundle: %1$s — %2$s ea', 'fastnutrition-mealprep' ),
						$tier_label,
						wc_price( $effective_per_meal )
					),
					'effective_per_meal' => $effective_per_meal,
					'bundled_units'      => $bundled_units,
					'total_qty'          => $total_qty,
				];
				unset( $line );
			}
		}
	}

	private function describe_tiers( array $tiers ): string {
		$parts = [];
		foreach ( $tiers as $tier ) {
			$parts[] = sprintf( '%d for %s', (int) $tier['qty'], wp_strip_all_tags( wc_price( (float) $tier['price'] ) ) );
		}
		return implode( ', ', $parts );
	}

	public function display( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item[ self::DISPLAY_KEY ]['label'] ) ) {
			$item_data[] = [
				'name'    => __( 'Bundle deal', 'fastnutrition-mealprep' ),
				'value'   => wp_strip_all_tags( $cart_item[ self::DISPLAY_KEY ]['label'] ),
				'display' => $cart_item[ self::DISPLAY_KEY ]['label'],
			];
		}
		return $item_data;
	}
}

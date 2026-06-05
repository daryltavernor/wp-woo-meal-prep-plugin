<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\BundleMeta;

/**
 * Single source of truth for meal line pricing.
 *
 * This class owns the price-derivation pipeline described in CHECKOUT_PRICING.md:
 *
 *   unit_price = ( bundle_applies ? bundle_effective_unit : catalog_base )
 *              + selection_delta   // ingredient swaps + add-on prices
 *
 * The logic was extracted verbatim from Cart\BundlePricer::apply() so that BOTH
 * the online cart (BundlePricer, on woocommerce_before_calculate_totals) and the
 * offline In-Store Quick Order builder (InStore\OrderFactory) price meals through
 * the exact same code. Online and offline orders therefore never fork on pricing,
 * bundle apportionment or meal composition.
 *
 * Two entry points:
 *  - price_product_group(): the pure, WordPress-free apportionment core. Given a
 *    catalog base, the bundle tiers and a set of lines (each with quantity + a
 *    pre-computed selection delta) for ONE product, it returns the per-line unit
 *    price plus the bundle meta. Unit-testable in isolation.
 *  - price_lines(): the WordPress-aware orchestrator used by OrderFactory. It
 *    groups arbitrary lines by product id, reads the catalog price, bundle tiers
 *    and selection delta from canonical sources, then delegates to
 *    price_product_group() per group.
 */
final class MealPricing {

	/**
	 * Price one product's worth of lines. Pure: no WordPress, no side effects.
	 *
	 * Bundle tiers are evaluated against the COMBINED quantity of every line in
	 * the group (mirroring how the cart groups identical meal products), and the
	 * bundle total is apportioned across the lines in integer pence with the
	 * rounding remainder handed to the final line, so the per-line totals always
	 * sum to exactly the bundle total. See BundlePricer::apply() history for the
	 * worked £50 / 15-meal example this guards against.
	 *
	 * @param float                                   $catalog_base Product catalog price.
	 * @param array<int,array{qty:int,price:float}>   $bundles      Bundle tiers.
	 * @param array<string|int,array{quantity:int,delta:float}> $lines Ordered map of line key => qty + selection delta.
	 * @return array<string|int,array{unit_price:float,bundle:?array}> Same keys → unit price + bundle meta (or null).
	 */
	public static function price_product_group( float $catalog_base, array $bundles, array $lines ): array {
		$total_qty = 0;
		foreach ( $lines as $line ) {
			$total_qty += (int) $line['quantity'];
		}

		$result         = BundlePricer::calculate( $total_qty, $bundles );
		$bundle_applied = ! empty( $result['applied'] );
		$out            = [];

		if ( ! $bundle_applied ) {
			foreach ( $lines as $key => $line ) {
				$delta        = (float) $line['delta'];
				$out[ $key ]  = [
					'unit_price' => max( 0.0, $catalog_base + $delta ),
					'bundle'     => null,
				];
			}
			return $out;
		}

		// Bundle applies. The bundle total covers the meal BASE for every unit in
		// the group; per-line add-on deltas are charged on top. Apportion the
		// bundle total across the lines in integer pence and hand the rounding
		// remainder to the final line, so the per-line totals sum to exactly the
		// bundle total (WC rounds each line's unit×qty independently).
		$total_pence = (int) round( (float) $result['total'] * 100 );
		$assigned    = 0;
		$line_count  = count( $lines );
		$index       = 0;

		foreach ( $lines as $key => $line ) {
			++$index;
			$qty = (int) $line['quantity'];

			if ( $index === $line_count ) {
				$meal_pence = $total_pence - $assigned;
			} else {
				$meal_pence = $total_qty > 0 ? (int) round( $total_pence * $qty / $total_qty ) : 0;
				$assigned  += $meal_pence;
			}

			$delta           = (float) $line['delta'];
			$meal_line_total = $meal_pence / 100;
			$unit_price      = $qty > 0 ? max( 0.0, ( $meal_line_total / $qty ) + $delta ) : 0.0;

			$out[ $key ] = [
				'unit_price' => $unit_price,
				'bundle'     => [
					'applied_tier'    => $result['tier'],
					'effective_unit'  => $result['effective_unit'],
					'bundle_units'    => $total_qty,
					'remainder_units' => 0,
					'bundle_total'    => $result['total'],
					'per_meal_rate'   => $result['per_meal_rate'],
					'threshold_qty'   => $result['threshold_qty'],
					'extra_qty'       => $result['extra_qty'],
					'bundle_price'    => $result['bundle_price'],
				],
			];
		}

		return $out;
	}

	/**
	 * Price an arbitrary set of meal lines (used by the offline order builder).
	 *
	 * Reads each product's catalog price and bundle tiers, computes the selection
	 * delta via the canonical Selections::compute_price_delta(), groups by product
	 * id so bundle tiers consider the combined quantity, and delegates to
	 * price_product_group().
	 *
	 * @param array<string|int,array{product_id:int,quantity:int,selection:array}> $lines
	 * @return array<string|int,array{unit_price:float,bundle:?array}>
	 */
	public static function price_lines( array $lines ): array {
		$groups        = [];
		$catalog_cache = [];

		foreach ( $lines as $key => $line ) {
			$pid = (int) $line['product_id'];
			if ( ! isset( $catalog_cache[ $pid ] ) ) {
				$product               = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$catalog_cache[ $pid ] = $product ? (float) $product->get_price( 'edit' ) : 0.0;
			}
			$groups[ $pid ][ $key ] = [
				'quantity' => max( 1, (int) $line['quantity'] ),
				'delta'    => Selections::compute_price_delta( $pid, (array) ( $line['selection'] ?? [] ) ),
			];
		}

		$out = [];
		foreach ( $groups as $pid => $group ) {
			$bundles = BundleMeta::get_bundles( (int) $pid );
			$priced  = self::price_product_group( $catalog_cache[ $pid ], $bundles, $group );
			foreach ( $priced as $key => $res ) {
				$out[ $key ] = $res;
			}
		}

		return $out;
	}
}

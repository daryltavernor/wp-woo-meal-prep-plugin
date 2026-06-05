<?php
/**
 * Unit tests for the shared meal pricing core.
 *
 * Cart\MealPricing::price_product_group() is the single source of truth used by
 * BOTH the online cart (Cart\BundlePricer::apply) and the offline In-Store order
 * builder (InStore\OrderFactory). These tests lock in the pricing invariant —
 * unit_price = (bundle effective or catalog base) + selection delta — and the
 * integer-pence bundle apportionment that guarantees per-line totals sum to the
 * exact bundle total.
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use FastNutrition\MealPrep\Cart\MealPricing;
use PHPUnit\Framework\TestCase;

final class MealPricingTest extends TestCase {

	/** Round a line's total the way WooCommerce stores it (unit × qty, 2dp). */
	private function lineTotal( array $res, int $qty ): float {
		return round( (float) $res['unit_price'] * $qty, 2 );
	}

	public function test_no_bundle_is_base_plus_delta(): void {
		$out = MealPricing::price_product_group( 6.0, [], [ 'a' => [ 'quantity' => 1, 'delta' => 1.5 ] ] );
		$this->assertSame( 7.5, $out['a']['unit_price'] );
		$this->assertNull( $out['a']['bundle'] );
	}

	public function test_negative_delta_is_floored_at_zero(): void {
		$out = MealPricing::price_product_group( 2.0, [], [ 'a' => [ 'quantity' => 1, 'delta' => -10.0 ] ] );
		$this->assertSame( 0.0, $out['a']['unit_price'] );
	}

	public function test_bundle_apportions_to_exact_total_across_lines(): void {
		// The documented edge case: 15 meals at £50 split across three 5-meal
		// lines must sum to exactly £50.00, not £50.01.
		$bundles = [ [ 'qty' => 15, 'price' => 50.0 ] ];
		$lines   = [
			'l1' => [ 'quantity' => 5, 'delta' => 0.0 ],
			'l2' => [ 'quantity' => 5, 'delta' => 0.0 ],
			'l3' => [ 'quantity' => 5, 'delta' => 0.0 ],
		];
		$out = MealPricing::price_product_group( 5.0, $bundles, $lines );

		$sum = $this->lineTotal( $out['l1'], 5 ) + $this->lineTotal( $out['l2'], 5 ) + $this->lineTotal( $out['l3'], 5 );
		$this->assertEqualsWithDelta( 50.0, $sum, 0.0001 );
		$this->assertSame( 50.0, $out['l1']['bundle']['bundle_total'] );
		$this->assertSame( 15, $out['l1']['bundle']['bundle_units'] );
	}

	public function test_bundle_charges_addon_delta_on_top(): void {
		// 15 meals at £50 base + a £1 add-on on the 5-meal line = £55.00 overall.
		$bundles = [ [ 'qty' => 15, 'price' => 50.0 ] ];
		$lines   = [
			'l1' => [ 'quantity' => 10, 'delta' => 0.0 ],
			'l2' => [ 'quantity' => 5, 'delta' => 1.0 ],
		];
		$out = MealPricing::price_product_group( 5.0, $bundles, $lines );

		$sum = $this->lineTotal( $out['l1'], 10 ) + $this->lineTotal( $out['l2'], 5 );
		$this->assertEqualsWithDelta( 55.0, $sum, 0.0001 );
	}

	public function test_exact_tier_single_line(): void {
		$out = MealPricing::price_product_group( 5.0, [ [ 'qty' => 10, 'price' => 35.0 ] ], [ 'x' => [ 'quantity' => 10, 'delta' => 0.0 ] ] );
		$this->assertEqualsWithDelta( 35.0, $this->lineTotal( $out['x'], 10 ), 0.0001 );
	}

	public function test_above_threshold_extras_at_rounded_rate(): void {
		// tier 10/£35 → per-meal rate ceil(3500/10)=350 → £3.50; 11 meals = £38.50.
		$out = MealPricing::price_product_group( 5.0, [ [ 'qty' => 10, 'price' => 35.0 ] ], [ 'x' => [ 'quantity' => 11, 'delta' => 0.0 ] ] );
		$this->assertEqualsWithDelta( 38.5, $this->lineTotal( $out['x'], 11 ), 0.0001 );
	}
}

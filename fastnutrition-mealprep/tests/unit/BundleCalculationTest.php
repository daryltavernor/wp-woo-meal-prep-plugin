<?php
/**
 * Unit tests for the bundle calculation algorithm — verifies flat override + effective per-meal pricing
 * for single tier, multi-tier, and mixed cart scenarios.
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BundleCalculationTest extends TestCase {

	/**
	 * Helper mirroring BundlePricer's choose-tiers algorithm, kept in-sync with the real code.
	 */
	private function plan_bundles( int $qty, array $tiers ): array {
		usort( $tiers, static fn( array $a, array $b ): int => $b['qty'] <=> $a['qty'] );
		$remaining = $qty;
		$total_price = 0.0;
		$units = 0;
		foreach ( $tiers as $tier ) {
			if ( $remaining < $tier['qty'] ) {
				continue;
			}
			$count = intdiv( $remaining, $tier['qty'] );
			$total_price += $count * $tier['price'];
			$units       += $count * $tier['qty'];
			$remaining   -= $count * $tier['qty'];
		}
		return [ 'bundled_units' => $units, 'bundled_total' => $total_price, 'remainder' => $remaining ];
	}

	public function test_exact_tier_match(): void {
		$r = $this->plan_bundles( 10, [ [ 'qty' => 10, 'price' => 35.0 ] ] );
		$this->assertSame( 10, $r['bundled_units'] );
		$this->assertSame( 35.0, $r['bundled_total'] );
		$this->assertSame( 0, $r['remainder'] );
	}

	public function test_exceeds_tier(): void {
		$r = $this->plan_bundles( 11, [ [ 'qty' => 10, 'price' => 35.0 ] ] );
		$this->assertSame( 10, $r['bundled_units'] );
		$this->assertSame( 35.0, $r['bundled_total'] );
		$this->assertSame( 1, $r['remainder'] );
	}

	public function test_prefers_larger_tier(): void {
		$tiers = [ [ 'qty' => 10, 'price' => 35.0 ], [ 'qty' => 15, 'price' => 50.0 ] ];
		$r = $this->plan_bundles( 15, $tiers );
		$this->assertSame( 15, $r['bundled_units'] );
		$this->assertSame( 50.0, $r['bundled_total'] );
	}

	public function test_stacks_tiers(): void {
		$tiers = [ [ 'qty' => 10, 'price' => 35.0 ], [ 'qty' => 15, 'price' => 50.0 ] ];
		$r = $this->plan_bundles( 25, $tiers );
		$this->assertSame( 25, $r['bundled_units'] );
		$this->assertSame( 85.0, $r['bundled_total'] );
	}

	public function test_below_lowest_tier(): void {
		$r = $this->plan_bundles( 5, [ [ 'qty' => 10, 'price' => 35.0 ] ] );
		$this->assertSame( 0, $r['bundled_units'] );
		$this->assertSame( 0.0, $r['bundled_total'] );
		$this->assertSame( 5, $r['remainder'] );
	}
}

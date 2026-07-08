<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use FastNutrition\MealPrep\Delivery\DeliveryThreshold;
use PHPUnit\Framework\TestCase;

final class DeliveryThresholdTest extends TestCase {

	public function test_rule_off_always_allows(): void {
		$this->assertTrue( DeliveryThreshold::is_allowed( 0.0, 10.0 ) );
		$this->assertTrue( DeliveryThreshold::is_allowed( 0.0, null ) );
	}

	public function test_unknown_cart_fails_open(): void {
		$this->assertTrue( DeliveryThreshold::is_allowed( 25.0, null ) );
	}

	public function test_blocks_below_threshold(): void {
		$this->assertFalse( DeliveryThreshold::is_allowed( 25.0, 24.99 ) );
		$this->assertFalse( DeliveryThreshold::is_allowed( 25.0, 0.0 ) );
	}

	public function test_allows_at_or_above_threshold(): void {
		$this->assertTrue( DeliveryThreshold::is_allowed( 25.0, 25.0 ) );  // exact
		$this->assertTrue( DeliveryThreshold::is_allowed( 25.0, 25.01 ) );
		$this->assertTrue( DeliveryThreshold::is_allowed( 25.0, 100.0 ) );
	}
}

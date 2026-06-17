<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use FastNutrition\MealPrep\InStore\PrepOrderStatus;
use PHPUnit\Framework\TestCase;

final class PrepOrderStatusTest extends TestCase {

	public function test_exclude_from_reports_removes_prep_statuses(): void {
		$s  = new PrepOrderStatus();
		$in = [ 'wc-completed', PrepOrderStatus::STATUS, PrepOrderStatus::SLUG, 'wc-processing' ];
		$this->assertSame( [ 'wc-completed', 'wc-processing' ], $s->exclude_from_reports( $in ) );
	}

	/**
	 * WooCommerce passes `false` to woocommerce_reports_order_statuses for
	 * unfiltered report queries; the callback must pass it through, not fatal.
	 */
	public function test_exclude_from_reports_passes_through_non_array(): void {
		$s = new PrepOrderStatus();
		$this->assertFalse( $s->exclude_from_reports( false ) );
		$this->assertSame( '', $s->exclude_from_reports( '' ) );
		$this->assertNull( $s->exclude_from_reports( null ) );
	}
}

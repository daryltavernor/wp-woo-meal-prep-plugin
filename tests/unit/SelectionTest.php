<?php
/**
 * Unit tests for the central selection interpreter.
 *
 * Cart\Selection::ingredient_ids() is the single basis for macros, price
 * deltas, the prep cache and prep-sheet totals, so these tests lock in how each
 * selection mode maps to its composition ingredient ids — including the generic
 * 'standalone' mode and the legacy 'sweet' mode kept for historical orders.
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use FastNutrition\MealPrep\Cart\Selection;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase {

	public function test_standalone_returns_the_single_item(): void {
		$sel = [ 'mode' => 'standalone', 'item_id' => 42, 'item_type' => 'sweet' ];
		$this->assertSame( [ 42 ], Selection::ingredient_ids( $sel ) );
		$this->assertTrue( Selection::is_single_item( $sel ) );
	}

	public function test_set_meal_returns_the_set_meal_id(): void {
		$this->assertSame( [ 7 ], Selection::ingredient_ids( [ 'mode' => 'set', 'set_meal_id' => 7 ] ) );
	}

	public function test_legacy_sweet_still_resolves(): void {
		// Historical orders placed before sweets were decoupled.
		$sel = [ 'mode' => 'sweet', 'sweet_id' => 9 ];
		$this->assertSame( [ 9 ], Selection::ingredient_ids( $sel ) );
		$this->assertTrue( Selection::is_single_item( $sel ) );
	}

	public function test_build_returns_protein_carb_greens_in_order(): void {
		$sel = [
			'mode'       => 'build',
			'protein_id' => 1,
			'carb_id'    => 2,
			'greens_ids' => [ 3 ],
		];
		$this->assertSame( [ 1, 2, 3 ], Selection::ingredient_ids( $sel ) );
		$this->assertFalse( Selection::is_single_item( $sel ) );
	}

	public function test_build_double_greens_has_no_carb(): void {
		$sel = [
			'mode'       => 'build',
			'protein_id' => 1,
			'carb_id'    => null,
			'greens_ids' => [ 3, 4 ],
		];
		$this->assertSame( [ 1, 3, 4 ], Selection::ingredient_ids( $sel ) );
	}

	public function test_empty_or_unknown_selection_is_empty(): void {
		$this->assertSame( [], Selection::ingredient_ids( [] ) );
		$this->assertSame( [], Selection::ingredient_ids( [ 'mode' => 'standalone', 'item_id' => 0 ] ) );
		$this->assertSame( [], Selection::ingredient_ids( [ 'mode' => 'build' ] ) );
	}

	public function test_zero_greens_ids_are_skipped(): void {
		$sel = [
			'mode'       => 'build',
			'protein_id' => 5,
			'greens_ids' => [ 0, 6, 0 ],
		];
		$this->assertSame( [ 5, 6 ], Selection::ingredient_ids( $sel ) );
	}
}

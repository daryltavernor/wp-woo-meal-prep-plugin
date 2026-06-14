<?php
/**
 * Regression tests for the WooCommerce add-to-cart filter callbacks in
 * Cart\Selections. WooCommerce calls these straight from the request, and for
 * VARIABLE products it passes $variation_id as an empty string '' (and the
 * quantity as a float). With declare(strict_types=1) an `int` hint on those
 * parameters throws a TypeError, fatalling every add-to-cart. These tests lock
 * in that the callbacks accept the loose types WooCommerce actually sends.
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

// Shadow get_post_meta() inside the Products namespace so MealProduct::
// is_configurable() resolves to "not a meal product", letting the callbacks
// return early without booting WordPress.
namespace FastNutrition\MealPrep\Products {
	if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
		function get_post_meta( $id, $key, $single = false ) {
			return '';
		}
	}
}

namespace FastNutrition\MealPrep\Tests\Unit {

	use FastNutrition\MealPrep\Cart\Selections;
	use PHPUnit\Framework\TestCase;

	final class SelectionsValidateTest extends TestCase {

		public function test_validate_accepts_string_variation_id_without_typeerror(): void {
			$selections = new Selections();
			// WC's variable-product handler passes variation_id as '' and a float
			// quantity; this must not throw a TypeError.
			$this->assertTrue(
				$selections->validate( true, '123', 1.0, '', [], [] )
			);
		}

		public function test_attach_selection_accepts_string_variation_id_without_typeerror(): void {
			$selections = new Selections();
			$data       = $selections->attach_selection( [ 'keep' => 1 ], '123', '' );
			// Not a configurable product → returned unchanged, and crucially no fatal.
			$this->assertSame( [ 'keep' => 1 ], $data );
		}
	}
}

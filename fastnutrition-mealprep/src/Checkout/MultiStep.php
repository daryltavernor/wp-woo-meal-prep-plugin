<?php
/**
 * Registers the Multi-Step Checkout wrapper block and the Slot Picker inner block.
 * The JS side progressively hides/shows the inner blocks to create a three-step flow.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

final class MultiStep {

	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
	}

	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type( FN_MEALPREP_PATH . 'src/Blocks/MultiStepCheckout' );
		register_block_type( FN_MEALPREP_PATH . 'src/Blocks/SlotPicker' );
	}
}

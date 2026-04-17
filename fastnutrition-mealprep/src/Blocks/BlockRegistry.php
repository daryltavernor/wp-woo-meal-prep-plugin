<?php
/**
 * Registers the meal builder and macro calculator blocks. (Checkout blocks are registered in MultiStep.)
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Blocks;

final class BlockRegistry {

	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ], 15 );
	}

	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type( FN_MEALPREP_PATH . 'src/Blocks/MealBuilder' );
		register_block_type( FN_MEALPREP_PATH . 'src/Blocks/MacroCalculator' );
	}
}

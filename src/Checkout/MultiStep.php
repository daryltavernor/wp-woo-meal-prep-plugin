<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

final class MultiStep {

	public function register(): void {
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$dir = FN_MEALPREP_DIR . 'assets/build/blocks/multi-step-checkout';
		if ( is_readable( $dir . '/block.json' ) ) {
			register_block_type( $dir );
		}
	}

	public function enqueue_frontend(): void {
		if ( ! is_checkout() ) {
			return;
		}
		wp_enqueue_style(
			'fn-multi-step-checkout',
			FN_MEALPREP_URL . 'assets/build/multi-step.css',
			[],
			FN_MEALPREP_VERSION
		);
		$script = FN_MEALPREP_DIR . 'assets/build/multi-step.js';
		if ( is_readable( $script ) ) {
			wp_enqueue_script(
				'fn-multi-step-checkout',
				FN_MEALPREP_URL . 'assets/build/multi-step.js',
				[ 'wp-element', 'wp-data', 'wc-blocks-checkout' ],
				FN_MEALPREP_VERSION,
				true
			);
		}
	}
}

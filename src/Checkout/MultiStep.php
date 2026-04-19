<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

use FastNutrition\MealPrep\Admin\SettingsPage;

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
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! SettingsPage::multistep_enabled() ) {
			return;
		}

		$build = FN_MEALPREP_DIR . 'assets/build/blocks/multi-step-checkout/';
		$url   = FN_MEALPREP_URL . 'assets/build/blocks/multi-step-checkout/';

		$view_js = $build . 'view.js';
		if ( ! is_readable( $view_js ) ) {
			return;
		}
		$asset = is_readable( $build . 'view.asset.php' ) ? include $build . 'view.asset.php' : [ 'dependencies' => [], 'version' => FN_MEALPREP_VERSION ];

		wp_enqueue_script(
			'fn-multi-step-checkout',
			$url . 'view.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? FN_MEALPREP_VERSION,
			true
		);
		if ( is_readable( $build . 'style-view.css' ) && ! SettingsPage::minimal_styling() ) {
			wp_enqueue_style(
				'fn-multi-step-checkout',
				$url . 'style-view.css',
				[],
				FN_MEALPREP_VERSION
			);
		}
		wp_localize_script(
			'fn-multi-step-checkout',
			'fnMultiStep',
			[
				'restUrl' => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}

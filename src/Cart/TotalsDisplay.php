<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Admin\SettingsPage;

final class TotalsDisplay {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
	}

	public function enqueue_frontend(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		$build = FN_MEALPREP_DIR . 'assets/build/blocks/cart-totals-extras/';
		$url   = FN_MEALPREP_URL . 'assets/build/blocks/cart-totals-extras/';

		$view_js = $build . 'view.js';
		if ( ! is_readable( $view_js ) ) {
			return;
		}

		$asset = is_readable( $build . 'view.asset.php' )
			? include $build . 'view.asset.php'
			: [ 'dependencies' => [ 'wp-data', 'wp-i18n' ], 'version' => FN_MEALPREP_VERSION ];

		wp_enqueue_script(
			'fn-cart-totals-extras',
			$url . 'view.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? FN_MEALPREP_VERSION,
			true
		);

		if ( is_readable( $build . 'style-view.css' ) && ! SettingsPage::minimal_styling() ) {
			wp_enqueue_style(
				'fn-cart-totals-extras',
				$url . 'style-view.css',
				[],
				FN_MEALPREP_VERSION
			);
		}
	}
}

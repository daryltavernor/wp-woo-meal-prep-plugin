<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Admin\SettingsPage;

final class TotalsDisplay {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
	}

	public function enqueue_frontend(): void {
		if ( ! $this->page_is_relevant() ) {
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

	private function page_is_relevant(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		global $post;
		if ( $post && function_exists( 'has_block' ) ) {
			if ( has_block( 'woocommerce/cart', $post ) || has_block( 'woocommerce/checkout', $post ) ) {
				return true;
			}
		}
		return false;
	}
}

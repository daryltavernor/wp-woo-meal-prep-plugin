<?php
/**
 * Registers and enqueues build assets. Reads the wp-scripts generated asset files (*.asset.php) if present.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Support;

final class AssetManager {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	public function enqueue_front(): void {
		$this->register_handle( 'fn-meal-builder', 'meal-builder', true );
		$this->register_handle( 'fn-macro-calculator', 'macro-calculator', true );
		$this->register_handle( 'fn-multistep-checkout', 'multistep-checkout', true );
		$this->register_handle( 'fn-slot-picker', 'slot-picker', true );
		wp_enqueue_style( 'fn-front', FN_MEALPREP_URL . 'assets/build/front.css', [], FN_MEALPREP_VERSION );
	}

	public function enqueue_admin( string $hook ): void {
		$pages = [ 'woocommerce_page_fn-prep-dashboard', 'woocommerce_page_fn-prep-sheet', 'woocommerce_page_fn-profiles', 'woocommerce_page_fn-blocked-dates' ];
		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}
		$this->register_handle( 'fn-admin', 'admin', false );
		wp_enqueue_style( 'fn-admin', FN_MEALPREP_URL . 'assets/build/admin.css', [], FN_MEALPREP_VERSION );
		wp_enqueue_script( 'fn-admin' );
	}

	/**
	 * Register a compiled bundle. When the asset file is missing (e.g. dev without running build), we
	 * degrade gracefully by registering without the deps so that PHP still functions.
	 */
	private function register_handle( string $handle, string $name, bool $frontend ): void {
		$asset_file = FN_MEALPREP_PATH . "assets/build/{$name}.asset.php";
		$script_url = FN_MEALPREP_URL . "assets/build/{$name}.js";
		$deps       = [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ];
		$version    = FN_MEALPREP_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = require $asset_file;
			$deps    = $asset['dependencies'] ?? $deps;
			$version = $asset['version'] ?? $version;
		}

		wp_register_script( $handle, $script_url, $deps, $version, true );
		wp_set_script_translations( $handle, 'fastnutrition-mealprep' );

		if ( $frontend ) {
			wp_localize_script(
				$handle,
				'FN_MEALPREP',
				[
					'rest'  => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				]
			);
		}
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Support;

final class AssetManager {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		add_action( 'init', [ $this, 'register_blocks' ] );
	}

	public function register_frontend_assets(): void {
		$build = FN_MEALPREP_DIR . 'assets/build/blocks/';
		$url   = FN_MEALPREP_URL . 'assets/build/blocks/';

		$this->register_pair(
			'fn-meal-builder',
			$build . 'meal-builder/view.js',
			$url . 'meal-builder/view.js',
			$build . 'meal-builder/view.asset.php',
			$build . 'meal-builder/style-view.css',
			$url . 'meal-builder/style-view.css'
		);

		$this->register_pair(
			'fn-macro-calculator',
			$build . 'macro-calculator/view.js',
			$url . 'macro-calculator/view.js',
			$build . 'macro-calculator/view.asset.php',
			$build . 'macro-calculator/style-view.css',
			$url . 'macro-calculator/style-view.css'
		);

		if ( wp_script_is( 'fn-macro-calculator', 'registered' ) ) {
			wp_localize_script(
				'fn-macro-calculator',
				'fnMacroCalc',
				[
					'restUrl'  => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'loggedIn' => is_user_logged_in(),
				]
			);
		}
		if ( wp_script_is( 'fn-meal-builder', 'registered' ) ) {
			wp_localize_script(
				'fn-meal-builder',
				'fnMealBuilder',
				[
					'restUrl' => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				]
			);
		}
	}

	public function register_blocks(): void {
		$blocks = [
			'meal-builder',
			'macro-calculator',
			'multi-step-checkout',
			'slot-picker',
		];
		foreach ( $blocks as $slug ) {
			$dir = FN_MEALPREP_DIR . 'assets/build/blocks/' . $slug;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	private function register_pair( string $handle, string $jsPath, string $jsUrl, string $assetPath, string $cssPath, string $cssUrl ): void {
		if ( is_readable( $jsPath ) ) {
			$asset = $this->load_asset_file( $assetPath );
			wp_register_script( $handle, $jsUrl, $asset['dependencies'], $asset['version'], true );
		}
		if ( is_readable( $cssPath ) ) {
			wp_register_style( $handle, $cssUrl, [], FN_MEALPREP_VERSION );
		}
	}

	private function load_asset_file( string $path ): array {
		if ( is_readable( $path ) ) {
			$data = include $path;
			if ( is_array( $data ) && isset( $data['dependencies'], $data['version'] ) ) {
				return $data;
			}
		}
		return [
			'dependencies' => [ 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-components' ],
			'version'      => FN_MEALPREP_VERSION,
		];
	}
}

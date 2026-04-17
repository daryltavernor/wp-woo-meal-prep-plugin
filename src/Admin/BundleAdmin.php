<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

/**
 * Bundles are configured per product (see Products\BundleMeta).
 * This class surfaces a help column on the Products admin list so that
 * bundle-enabled products are easy to spot.
 */
final class BundleAdmin {

	public function register(): void {
		add_filter( 'manage_edit-product_columns', [ $this, 'add_column' ] );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function add_column( array $columns ): array {
		$columns['fn_bundle'] = __( 'Bundle', 'fastnutrition-mealprep' );
		return $columns;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'fn_bundle' !== $column ) {
			return;
		}
		$bundles = \FastNutrition\MealPrep\Products\BundleMeta::get_bundles( $post_id );
		if ( empty( $bundles ) ) {
			echo '—';
			return;
		}
		$parts = [];
		foreach ( $bundles as $b ) {
			$parts[] = sprintf( '%d/%s', (int) $b['qty'], wc_price( (float) $b['price'] ) );
		}
		echo wp_kses_post( implode( ' · ', $parts ) );
	}
}

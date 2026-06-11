<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use WP_Post;

/**
 * "Popular Combinations" — sells the shop's most-ordered build combinations
 * (protein + carb + greens) from a single dropdown, like a Standalone Product
 * but populated automatically from sales data (see Stats\PopularCombos).
 *
 * It's a standalone product mode: when enabled, the product page shows the
 * popular-combinations picker instead of the full meal builder or a standalone
 * item list. A chosen combination becomes an ordinary `build` selection, so it
 * flows through pricing, macros, the prep sheet, labels and the Quick Order
 * tools exactly like a hand-built meal — no new cart logic.
 *
 * Admin is a single on/off toggle. The product still needs a base price (and,
 * optionally, a bulk tier / bundle pricing) like any meal product; double greens
 * are allowed implicitly so any popular combo can be re-added (see
 * MealProduct::get_config).
 */
final class PopularCombosProduct {

	public const TAB_ID      = 'fn_popular_combos';
	public const META_ENABLED = '_fn_popular_combos_enabled';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
	}

	public function add_product_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = [
			'label'    => __( 'Popular Combinations', 'fastnutrition-mealprep' ),
			'target'   => 'fn_popular_combos_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 67,
		];
		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( 'fn_save_popular_combos_product', 'fn_popular_combos_nonce' );

		echo '<div id="fn_popular_combos_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group" style="padding:10px 14px">';
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:4px 0 10px">';
		echo '<strong>' . esc_html__( 'Popular Combinations — what this does', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Turns this product page into a dropdown of the shop\'s most-ordered meal combinations, refreshed weekly from sales. The customer just picks one and adds it to the basket; pricing, macros, labels and prep all work automatically. Set this product\'s base price (and bulk tier / bundle pricing) as you would for a meal product. Use this on its own — leave Meal Builder and Standalone Product disabled.', 'fastnutrition-mealprep' );
		echo '</div>';
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			[
				'id'          => self::META_ENABLED,
				'label'       => __( 'Enable popular combinations', 'fastnutrition-mealprep' ),
				'description' => __( 'Show the top combinations dropdown on this product page.', 'fastnutrition-mealprep' ),
				'value'       => self::is_enabled( $post->ID ) ? 'yes' : 'no',
			]
		);
		echo '</div></div>';
	}

	public function save( int $product_id ): void {
		if ( ! isset( $_POST['fn_popular_combos_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_popular_combos_nonce'] ) ), 'fn_save_popular_combos_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		update_post_meta( $product_id, self::META_ENABLED, ! empty( $_POST[ self::META_ENABLED ] ) );
	}

	public static function is_enabled( int $product_id ): bool {
		return (bool) get_post_meta( $product_id, self::META_ENABLED, true );
	}
}

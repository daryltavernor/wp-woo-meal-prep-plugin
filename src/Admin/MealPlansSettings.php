<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

/**
 * "Meal Plans" settings.
 *
 * Lets staff flag which plain WooCommerce variable products are "meal plans"
 * (sold outside the meal-builder facilities) and which of their variations to
 * count. The next-7-days fulfilment table on the Orders screen then shows how
 * many of each meal-plan variation to produce per day — kept entirely separate
 * from the meal totals.
 *
 * Stored as one option: product id => [ excluded variation ids ]. A configured
 * product with no exclusions counts all its variations (the default).
 */
final class MealPlansSettings {

	public const PAGE_SLUG = 'fn-meal-plans';
	public const OPTION    = 'fn_meal_plans';

	/** @return array<int,int[]> product id => excluded variation ids */
	public static function get_config(): array {
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $pid => $excluded ) {
			$out[ (int) $pid ] = array_values( array_map( 'intval', is_array( $excluded ) ? $excluded : [] ) );
		}
		return $out;
	}

	/** Whether a line item's product+variation is a counted meal-plan unit. */
	public static function is_meal_plan_line( int $product_id, int $variation_id ): bool {
		$config = self::get_config();
		if ( ! isset( $config[ $product_id ] ) ) {
			return false;
		}
		return $variation_id > 0 && ! in_array( $variation_id, $config[ $product_id ], true );
	}

	/** Short, human label for a variation, e.g. "Weekly Plan — Standard". */
	public static function variation_label( int $variation_id ): string {
		$v = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
		if ( ! $v ) {
			return '#' . $variation_id;
		}
		$name  = $v->get_name();
		$attrs = function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $v, true ) ) : '';
		// get_name() already includes attributes in modern WooCommerce; only append
		// when it doesn't, to avoid duplication.
		if ( '' !== $attrs && false === stripos( $name, $attrs ) ) {
			$name .= ' — ' . $attrs;
		}
		return $name;
	}

	public static function render_static(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Save.
		if ( isset( $_POST['fn_meal_plans_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_meal_plans_nonce'] ) ), 'fn_save_meal_plans' ) ) {
			$selected = isset( $_POST['fn_mp_product'] ) && is_array( $_POST['fn_mp_product'] )
				? array_map( 'intval', wp_unslash( $_POST['fn_mp_product'] ) )
				: [];
			$included = isset( $_POST['fn_mp_include'] ) && is_array( $_POST['fn_mp_include'] )
				? wp_unslash( $_POST['fn_mp_include'] )
				: [];

			$config = [];
			foreach ( $selected as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_type( 'variable' ) ) {
					continue;
				}
				$all_vars    = array_map( 'intval', $product->get_children() );
				$kept        = isset( $included[ $pid ] ) && is_array( $included[ $pid ] ) ? array_map( 'intval', $included[ $pid ] ) : $all_vars;
				$config[ $pid ] = array_values( array_diff( $all_vars, $kept ) ); // store the excluded ones.
			}
			update_option( self::OPTION, $config, false );
			UpcomingFulfilment::flush();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Meal plan settings saved.', 'fastnutrition-mealprep' ) . '</p></div>';
		}

		$config   = self::get_config();
		$products = wc_get_products(
			[
				'type'   => 'variable',
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'objects',
			]
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Meal Plans', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<p>' . esc_html__( 'Tick the variable products that are meal plans. The Orders screen will then show how many of each selected variation to produce per day. All variations are counted by default — untick any you want to ignore. This is separate from the meal totals.', 'fastnutrition-mealprep' ) . '</p>';

		if ( empty( $products ) ) {
			echo '<p><em>' . esc_html__( 'No variable products found.', 'fastnutrition-mealprep' ) . '</em></p></div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'fn_save_meal_plans', 'fn_meal_plans_nonce' );

		foreach ( $products as $product ) {
			$pid          = (int) $product->get_id();
			$is_plan      = isset( $config[ $pid ] );
			$excluded     = $config[ $pid ] ?? [];
			echo '<fieldset style="border:1px solid #c3c4c7;background:#fff;padding:10px 14px;margin:10px 0;max-width:680px;">';
			printf(
				'<label style="font-weight:600;"><input type="checkbox" name="fn_mp_product[]" value="%1$d" %2$s /> %3$s</label>',
				$pid,
				checked( $is_plan, true, false ),
				esc_html( $product->get_name() )
			);
			echo '<div style="margin:8px 0 0 24px;">';
			echo '<p class="description" style="margin:0 0 4px;">' . esc_html__( 'Variations to count:', 'fastnutrition-mealprep' ) . '</p>';
			foreach ( array_map( 'intval', $product->get_children() ) as $vid ) {
				// Included unless explicitly excluded (or product not yet a plan → default include).
				$inc = ! in_array( $vid, $excluded, true );
				printf(
					'<label style="display:block;margin-left:4px;"><input type="checkbox" name="fn_mp_include[%1$d][]" value="%2$d" %3$s /> %4$s</label>',
					$pid,
					$vid,
					checked( $inc, true, false ),
					esc_html( self::variation_label( $vid ) )
				);
			}
			echo '</div></fieldset>';
		}

		submit_button( __( 'Save meal plans', 'fastnutrition-mealprep' ) );
		echo '</form></div>';
	}
}

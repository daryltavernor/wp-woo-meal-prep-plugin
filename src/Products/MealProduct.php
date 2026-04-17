<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Post;

final class MealProduct {

	public const TAB_ID = 'fn_meal_builder';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		add_action( 'woocommerce_process_product_meta_simple', [ $this, 'save' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
		add_action( 'wp', [ $this, 'maybe_replace_add_to_cart' ] );
	}

	public function maybe_replace_add_to_cart(): void {
		if ( ! is_product() ) {
			return;
		}
		$product_id = get_queried_object_id();
		if ( ! self::is_meal( (int) $product_id ) ) {
			return;
		}
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action(
			'woocommerce_single_product_summary',
			static function () use ( $product_id ): void {
				wp_enqueue_script( 'fn-meal-builder' );
				wp_enqueue_style( 'fn-meal-builder' );
				printf(
					'<div class="fn-meal-builder-mount" data-fn-meal-builder="1" data-product-id="%d"></div>',
					(int) $product_id
				);
			},
			30
		);
	}

	public function add_product_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = [
			'label'    => __( 'Meal Builder', 'fastnutrition-mealprep' ),
			'target'   => 'fn_meal_builder_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 65,
		];
		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( 'fn_save_meal_product', 'fn_meal_product_nonce' );

		$is_meal               = (bool) get_post_meta( $post->ID, '_fn_is_meal', true );
		$tier                  = get_post_meta( $post->ID, '_fn_meal_tier', true ) ?: 'standard';
		$allow_double_greens   = (bool) get_post_meta( $post->ID, '_fn_allow_double_greens', true );
		$allow_set_meal_mode   = (bool) get_post_meta( $post->ID, '_fn_allow_set_meal_mode', true );
		$allowed_proteins      = (array) get_post_meta( $post->ID, '_fn_allowed_protein_ids', true );
		$allowed_carbs         = (array) get_post_meta( $post->ID, '_fn_allowed_carb_ids', true );
		$allowed_greens        = (array) get_post_meta( $post->ID, '_fn_allowed_greens_ids', true );
		$allowed_set_meals     = (array) get_post_meta( $post->ID, '_fn_allowed_set_meal_ids', true );

		echo '<div id="fn_meal_builder_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_is_meal',
				'label'       => __( 'Enable meal builder', 'fastnutrition-mealprep' ),
				'description' => __( 'Customers can build this product by choosing ingredients.', 'fastnutrition-mealprep' ),
				'value'       => $is_meal ? 'yes' : 'no',
			]
		);

		woocommerce_wp_select(
			[
				'id'      => '_fn_meal_tier',
				'label'   => __( 'Meal tier', 'fastnutrition-mealprep' ),
				'options' => [
					'standard' => __( 'Standard', 'fastnutrition-mealprep' ),
					'bulk'     => __( 'Bulk', 'fastnutrition-mealprep' ),
				],
				'value'   => $tier,
			]
		);

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_allow_double_greens',
				'label'       => __( 'Allow double greens', 'fastnutrition-mealprep' ),
				'description' => __( 'Let customers swap the carb for a second greens.', 'fastnutrition-mealprep' ),
				'value'       => $allow_double_greens ? 'yes' : 'no',
			]
		);

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_allow_set_meal_mode',
				'label'       => __( 'Allow set meal mode', 'fastnutrition-mealprep' ),
				'description' => __( 'Let customers choose a pre-made set meal instead of building.', 'fastnutrition-mealprep' ),
				'value'       => $allow_set_meal_mode ? 'yes' : 'no',
			]
		);

		echo '</div><div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Allowed ingredients (leave blank for all active of that type)', 'fastnutrition-mealprep' ) . '</strong></p>';

		$this->render_ingredient_multiselect( 'proteins', '_fn_allowed_protein_ids', __( 'Allowed proteins', 'fastnutrition-mealprep' ), $allowed_proteins, 'protein' );
		$this->render_ingredient_multiselect( 'carbs', '_fn_allowed_carb_ids', __( 'Allowed carbs', 'fastnutrition-mealprep' ), $allowed_carbs, 'carb' );
		$this->render_ingredient_multiselect( 'greens', '_fn_allowed_greens_ids', __( 'Allowed greens', 'fastnutrition-mealprep' ), $allowed_greens, 'greens' );
		$this->render_ingredient_multiselect( 'set_meals', '_fn_allowed_set_meal_ids', __( 'Allowed set meals', 'fastnutrition-mealprep' ), $allowed_set_meals, 'set_meal' );

		echo '</div></div>';
	}

	private function render_ingredient_multiselect( string $handle, string $key, string $label, array $selected, string $type_slug ): void {
		$ingredients = $this->get_ingredients_by_type( $type_slug );
		printf( '<p class="form-field"><label for="fn_select_%1$s">%2$s</label>', esc_attr( $handle ), esc_html( $label ) );
		printf( '<select multiple id="fn_select_%1$s" name="%2$s[]" style="width:50%%;min-height:100px;">', esc_attr( $handle ), esc_attr( $key ) );
		foreach ( $ingredients as $ingredient ) {
			$is_selected = in_array( $ingredient->ID, array_map( 'intval', $selected ), true );
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $ingredient->ID,
				selected( $is_selected, true, false ),
				esc_html( $ingredient->post_title )
			);
		}
		echo '</select></p>';
	}

	private function get_ingredients_by_type( string $type_slug ): array {
		return get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [
					[
						'taxonomy' => IngredientType::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $type_slug,
					],
				],
			]
		);
	}

	public function save( int $product_id ): void {
		if ( ! isset( $_POST['fn_meal_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_meal_product_nonce'] ) ), 'fn_save_meal_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		update_post_meta( $product_id, '_fn_is_meal', ! empty( $_POST['_fn_is_meal'] ) );
		update_post_meta( $product_id, '_fn_allow_double_greens', ! empty( $_POST['_fn_allow_double_greens'] ) );
		update_post_meta( $product_id, '_fn_allow_set_meal_mode', ! empty( $_POST['_fn_allow_set_meal_mode'] ) );

		$tier = isset( $_POST['_fn_meal_tier'] ) ? sanitize_key( wp_unslash( $_POST['_fn_meal_tier'] ) ) : 'standard';
		update_post_meta( $product_id, '_fn_meal_tier', in_array( $tier, [ 'standard', 'bulk' ], true ) ? $tier : 'standard' );

		foreach ( [ '_fn_allowed_protein_ids', '_fn_allowed_carb_ids', '_fn_allowed_greens_ids', '_fn_allowed_set_meal_ids' ] as $key ) {
			$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] )
				? array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) )
				: [];
			update_post_meta( $product_id, $key, array_values( $values ) );
		}
	}

	public static function is_meal( int $product_id ): bool {
		return (bool) get_post_meta( $product_id, '_fn_is_meal', true );
	}

	public static function get_config( int $product_id ): array {
		return [
			'is_meal'              => self::is_meal( $product_id ),
			'tier'                 => get_post_meta( $product_id, '_fn_meal_tier', true ) ?: 'standard',
			'allow_double_greens'  => (bool) get_post_meta( $product_id, '_fn_allow_double_greens', true ),
			'allow_set_meal_mode'  => (bool) get_post_meta( $product_id, '_fn_allow_set_meal_mode', true ),
			'allowed_proteins'     => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_protein_ids', true ) ),
			'allowed_carbs'        => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_carb_ids', true ) ),
			'allowed_greens'       => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_greens_ids', true ) ),
			'allowed_set_meals'    => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_set_meal_ids', true ) ),
		];
	}
}

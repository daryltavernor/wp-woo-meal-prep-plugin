<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\PostTypes;

use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Post;

final class Ingredient {

	public const POST_TYPE = 'fn_ingredient';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'Ingredients', 'fastnutrition-mealprep' ),
				'labels'       => [
					'name'          => __( 'Ingredients & Set Meals', 'fastnutrition-mealprep' ),
					'singular_name' => __( 'Ingredient', 'fastnutrition-mealprep' ),
					'add_new'       => __( 'Add Ingredient', 'fastnutrition-mealprep' ),
					'add_new_item'  => __( 'Add New Ingredient', 'fastnutrition-mealprep' ),
					'edit_item'     => __( 'Edit Ingredient', 'fastnutrition-mealprep' ),
					'menu_name'     => __( 'Ingredients', 'fastnutrition-mealprep' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'fn-mealprep',
				'show_in_rest' => true,
				'supports'     => [ 'title', 'thumbnail', 'excerpt' ],
				'taxonomies'   => [ IngredientType::TAXONOMY, Allergen::TAXONOMY ],
				'capabilities' => [
					'edit_post'          => 'edit_product',
					'read_post'          => 'read_product',
					'delete_post'        => 'delete_product',
					'edit_posts'         => 'edit_products',
					'edit_others_posts'  => 'edit_others_products',
					'publish_posts'      => 'publish_products',
					'read_private_posts' => 'read_private_products',
				],
				'map_meta_cap' => true,
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_macros',
			[
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => [
					'schema' => [
						'type'       => 'object',
						'properties' => [
							'kcal'      => [ 'type' => 'number' ],
							'protein_g' => [ 'type' => 'number' ],
							'carbs_g'   => [ 'type' => 'number' ],
							'fat_g'     => [ 'type' => 'number' ],
							'fibre_g'   => [ 'type' => 'number' ],
						],
					],
				],
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_tier',
			[
				'type'          => 'string',
				'single'        => true,
				'default'       => 'standard',
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_price_delta',
			[
				'type'          => 'number',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_active',
			[
				'type'          => 'boolean',
				'single'        => true,
				'default'       => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'fn_ingredient_details',
			__( 'Ingredient Details', 'fastnutrition-mealprep' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'fn_save_ingredient', 'fn_ingredient_nonce' );
		$macros      = get_post_meta( $post->ID, '_fn_macros', true );
		$macros      = is_array( $macros ) ? $macros : [];
		$tier        = get_post_meta( $post->ID, '_fn_tier', true ) ?: 'standard';
		$price_delta = get_post_meta( $post->ID, '_fn_price_delta', true ) ?: 0;
		$active      = get_post_meta( $post->ID, '_fn_active', true );
		$active      = '' === $active ? true : (bool) $active;

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin-bottom:12px;">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What is this for?', 'fastnutrition-mealprep' ) . '</strong> ';
		echo esc_html__( 'Ingredients are the building blocks for the meal builder. Use the "Ingredient Type" taxonomy (right sidebar) to mark each one as a Protein, Carb, Greens, or Set Meal. Set Meals are complete pre-made meals that customers can pick instead of building from components.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		$fields      = [
			'kcal'      => __( 'Calories (kcal)', 'fastnutrition-mealprep' ),
			'protein_g' => __( 'Protein (g)', 'fastnutrition-mealprep' ),
			'carbs_g'   => __( 'Carbs (g)', 'fastnutrition-mealprep' ),
			'fat_g'     => __( 'Fat (g)', 'fastnutrition-mealprep' ),
			'fibre_g'   => __( 'Fibre (g)', 'fastnutrition-mealprep' ),
		];
		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $label ) {
			$value = isset( $macros[ $key ] ) ? (float) $macros[ $key ] : 0.0;
			printf(
				'<tr><th><label for="fn_macros_%1$s">%2$s</label></th><td><input type="number" step="0.1" min="0" id="fn_macros_%1$s" name="fn_macros[%1$s]" value="%3$s" /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) $value )
			);
		}
		printf(
			'<tr><th><label for="fn_tier">%1$s</label></th><td><select id="fn_tier" name="fn_tier"><option value="standard" %2$s>%3$s</option><option value="bulk" %4$s>%5$s</option></select></td></tr>',
			esc_html__( 'Tier', 'fastnutrition-mealprep' ),
			selected( $tier, 'standard', false ),
			esc_html__( 'Standard', 'fastnutrition-mealprep' ),
			selected( $tier, 'bulk', false ),
			esc_html__( 'Bulk', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th><label for="fn_price_delta">%1$s</label></th><td><input type="number" step="0.01" id="fn_price_delta" name="fn_price_delta" value="%2$s" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Price modifier', 'fastnutrition-mealprep' ),
			esc_attr( (string) $price_delta ),
			esc_html__( 'Added to the meal price when this ingredient is chosen. Leave 0 for no extra charge.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th>%1$s</th><td><label><input type="checkbox" name="fn_active" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Active', 'fastnutrition-mealprep' ),
			checked( $active, true, false ),
			esc_html__( 'Available for customers to select', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
	}

	public function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['fn_ingredient_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_ingredient_nonce'] ) ), 'fn_save_ingredient' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw    = isset( $_POST['fn_macros'] ) && is_array( $_POST['fn_macros'] ) ? wp_unslash( $_POST['fn_macros'] ) : [];
		$macros = [
			'kcal'      => isset( $raw['kcal'] ) ? (float) $raw['kcal'] : 0,
			'protein_g' => isset( $raw['protein_g'] ) ? (float) $raw['protein_g'] : 0,
			'carbs_g'   => isset( $raw['carbs_g'] ) ? (float) $raw['carbs_g'] : 0,
			'fat_g'     => isset( $raw['fat_g'] ) ? (float) $raw['fat_g'] : 0,
			'fibre_g'   => isset( $raw['fibre_g'] ) ? (float) $raw['fibre_g'] : 0,
		];
		update_post_meta( $post_id, '_fn_macros', $macros );

		$tier = isset( $_POST['fn_tier'] ) ? sanitize_key( wp_unslash( $_POST['fn_tier'] ) ) : 'standard';
		update_post_meta( $post_id, '_fn_tier', in_array( $tier, [ 'standard', 'bulk' ], true ) ? $tier : 'standard' );

		$price_delta = isset( $_POST['fn_price_delta'] ) ? (float) $_POST['fn_price_delta'] : 0;
		update_post_meta( $post_id, '_fn_price_delta', $price_delta );

		update_post_meta( $post_id, '_fn_active', ! empty( $_POST['fn_active'] ) );
	}

	public function register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE,
			'fn_type_slug',
			[
				'get_callback' => static function ( array $post ): string {
					$terms = wp_get_post_terms( (int) $post['id'], IngredientType::TAXONOMY, [ 'fields' => 'slugs' ] );
					return is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
				},
				'schema'       => [ 'type' => 'string' ],
			]
		);
		register_rest_field(
			self::POST_TYPE,
			'fn_allergens',
			[
				'get_callback' => static function ( array $post ): array {
					$terms = wp_get_post_terms( (int) $post['id'], Allergen::TAXONOMY, [ 'fields' => 'slugs' ] );
					return is_array( $terms ) ? $terms : [];
				},
				'schema'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			]
		);
	}

	public static function get_macros( int $ingredient_id ): array {
		$macros = get_post_meta( $ingredient_id, '_fn_macros', true );
		$macros = is_array( $macros ) ? $macros : [];
		return [
			'kcal'      => (float) ( $macros['kcal'] ?? 0 ),
			'protein_g' => (float) ( $macros['protein_g'] ?? 0 ),
			'carbs_g'   => (float) ( $macros['carbs_g'] ?? 0 ),
			'fat_g'     => (float) ( $macros['fat_g'] ?? 0 ),
			'fibre_g'   => (float) ( $macros['fibre_g'] ?? 0 ),
		];
	}

	public static function get_type_slug( int $ingredient_id ): string {
		$terms = wp_get_post_terms( $ingredient_id, IngredientType::TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
	}
}

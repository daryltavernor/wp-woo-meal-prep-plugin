<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Taxonomies;

final class IngredientType {

	public const TAXONOMY = 'fn_ingredient_type';

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'init', [ $this, 'seed_default_terms' ], 11 );
	}

	public function seed_default_terms(): void {
		if ( get_option( 'fn_ingredient_types_seeded' ) ) {
			return;
		}
		$terms = [
			'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
		];
		$seeded = 0;
		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				$result = wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
				if ( ! is_wp_error( $result ) ) {
					$seeded++;
				}
			}
		}
		update_option( 'fn_ingredient_types_seeded', 1, false );
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			[ 'fn_ingredient' ],
			[
				'label'             => __( 'Ingredient Type', 'fastnutrition-mealprep' ),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
				'capabilities'      => [
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'edit_products',
				],
			]
		);
	}
}

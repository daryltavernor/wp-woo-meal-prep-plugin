<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Taxonomies;

final class Allergen {

	public const TAXONOMY = 'fn_allergen';

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'init', [ $this, 'seed_default_terms' ], 11 );
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			[ 'fn_ingredient' ],
			[
				'label'             => __( 'Allergens & Diet', 'fastnutrition-mealprep' ),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
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

	public function seed_default_terms(): void {
		if ( ! get_option( 'fn_allergens_seeded' ) ) {
			$defaults = [
				'gluten-free' => __( 'Gluten Free', 'fastnutrition-mealprep' ),
				'dairy-free'  => __( 'Dairy Free', 'fastnutrition-mealprep' ),
				'vegetarian'  => __( 'Vegetarian', 'fastnutrition-mealprep' ),
				'vegan'       => __( 'Vegan', 'fastnutrition-mealprep' ),
				'nut-free'    => __( 'Nut Free', 'fastnutrition-mealprep' ),
				'high-protein' => __( 'High Protein', 'fastnutrition-mealprep' ),
			];
			foreach ( $defaults as $slug => $name ) {
				if ( ! term_exists( $slug, self::TAXONOMY ) ) {
					wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
				}
			}
			update_option( 'fn_allergens_seeded', 1 );
		}
	}
}

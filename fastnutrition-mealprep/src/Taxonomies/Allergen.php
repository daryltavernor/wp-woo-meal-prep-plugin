<?php
/**
 * Allergen / dietary tag taxonomy for ingredients (GF, DF, V, VG, Nut-Free, etc.).
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Taxonomies;

final class Allergen {

	public const SLUG = 'fn_allergen';

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ], 5 );
		add_action( 'init', [ $this, 'seed_terms' ], 20 );
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::SLUG,
			[ 'fn_ingredient' ],
			[
				'labels'            => [
					'name'          => __( 'Allergens / Diet', 'fastnutrition-mealprep' ),
					'singular_name' => __( 'Allergen / Diet Tag', 'fastnutrition-mealprep' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			]
		);
	}

	public function seed_terms(): void {
		if ( ! taxonomy_exists( self::SLUG ) ) {
			return;
		}
		$defaults = [
			'gluten-free' => __( 'Gluten-Free', 'fastnutrition-mealprep' ),
			'dairy-free'  => __( 'Dairy-Free', 'fastnutrition-mealprep' ),
			'vegetarian'  => __( 'Vegetarian', 'fastnutrition-mealprep' ),
			'vegan'       => __( 'Vegan', 'fastnutrition-mealprep' ),
			'nut-free'    => __( 'Nut-Free', 'fastnutrition-mealprep' ),
			'contains-gluten' => __( 'Contains Gluten', 'fastnutrition-mealprep' ),
			'contains-dairy'  => __( 'Contains Dairy', 'fastnutrition-mealprep' ),
			'contains-nuts'   => __( 'Contains Nuts', 'fastnutrition-mealprep' ),
		];
		foreach ( $defaults as $slug => $name ) {
			if ( ! term_exists( $slug, self::SLUG ) ) {
				wp_insert_term( $name, self::SLUG, [ 'slug' => $slug ] );
			}
		}
	}
}

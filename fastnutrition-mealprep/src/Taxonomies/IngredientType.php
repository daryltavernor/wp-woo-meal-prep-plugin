<?php
/**
 * Ingredient Type taxonomy: protein | carb | greens | set_meal.
 * Terms are seeded on first registration.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Taxonomies;

final class IngredientType {

	public const SLUG = 'fn_ingredient_type';

	public const TERM_PROTEIN  = 'protein';
	public const TERM_CARB     = 'carb';
	public const TERM_GREENS   = 'greens';
	public const TERM_SET_MEAL = 'set_meal';

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
					'name'          => __( 'Ingredient Types', 'fastnutrition-mealprep' ),
					'singular_name' => __( 'Ingredient Type', 'fastnutrition-mealprep' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'meta_box_cb'       => false,
			]
		);
	}

	public function seed_terms(): void {
		if ( ! taxonomy_exists( self::SLUG ) ) {
			return;
		}
		$terms = [
			self::TERM_PROTEIN  => __( 'Protein', 'fastnutrition-mealprep' ),
			self::TERM_CARB     => __( 'Carb', 'fastnutrition-mealprep' ),
			self::TERM_GREENS   => __( 'Greens', 'fastnutrition-mealprep' ),
			self::TERM_SET_MEAL => __( 'Set Meal', 'fastnutrition-mealprep' ),
		];
		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, self::SLUG ) ) {
				wp_insert_term( $name, self::SLUG, [ 'slug' => $slug ] );
			}
		}
	}

	public static function all_slugs(): array {
		return [ self::TERM_PROTEIN, self::TERM_CARB, self::TERM_GREENS, self::TERM_SET_MEAL ];
	}
}

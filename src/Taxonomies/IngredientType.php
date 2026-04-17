<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Taxonomies;

final class IngredientType {

	public const TAXONOMY = 'fn_ingredient_type';

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
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

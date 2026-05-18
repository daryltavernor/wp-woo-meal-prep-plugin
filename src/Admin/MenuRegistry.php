<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class MenuRegistry {

	public const SLUG = 'fn-mealprep';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 50 );
		add_filter( 'parent_file', [ $this, 'highlight_parent' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Meal Prep', 'fastnutrition-mealprep' ),
			__( 'Meal Prep', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			self::SLUG,
			[ PrepDashboard::class, 'render_static' ],
			'dashicons-food',
			56
		);

		// Operational management.
		add_submenu_page( self::SLUG, __( 'Prep Dashboard', 'fastnutrition-mealprep' ), __( 'Prep Dashboard', 'fastnutrition-mealprep' ), 'manage_woocommerce', self::SLUG, [ PrepDashboard::class, 'render_static' ] );
		add_submenu_page( self::SLUG, __( 'Printable Prep Sheet', 'fastnutrition-mealprep' ), __( 'Prep Sheet', 'fastnutrition-mealprep' ), 'manage_woocommerce', 'fn-prep-sheet', [ PrepSheet::class, 'render_static' ] );

		// Ingredients (the CPT's show_in_menu auto-adds "Ingredients" itself — taxonomy submenus follow).
		add_submenu_page(
			self::SLUG,
			__( 'Ingredient Types', 'fastnutrition-mealprep' ),
			__( 'Ingredient Types', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'edit-tags.php?taxonomy=' . IngredientType::TAXONOMY . '&post_type=' . Ingredient::POST_TYPE
		);
		add_submenu_page(
			self::SLUG,
			__( 'Allergens & Diet Tags', 'fastnutrition-mealprep' ),
			__( 'Allergens', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'edit-tags.php?taxonomy=' . Allergen::TAXONOMY . '&post_type=' . Ingredient::POST_TYPE
		);

		// Delivery / collection config.
		add_submenu_page( self::SLUG, __( 'Delivery & Collection Profiles', 'fastnutrition-mealprep' ), __( 'Delivery Profiles', 'fastnutrition-mealprep' ), 'manage_woocommerce', 'fn-delivery-profiles', [ ProfileAdmin::class, 'render_static' ] );
		add_submenu_page( self::SLUG, __( 'Blocked Dates', 'fastnutrition-mealprep' ), __( 'Blocked Dates', 'fastnutrition-mealprep' ), 'manage_woocommerce', 'fn-blocked-dates', [ BlockedDatesAdmin::class, 'render_static' ] );

		// Settings.
		add_submenu_page( self::SLUG, __( 'Checkout & General Settings', 'fastnutrition-mealprep' ), __( 'Settings', 'fastnutrition-mealprep' ), 'manage_woocommerce', 'fn-settings', [ SettingsPage::class, 'render_static' ] );
	}

	/**
	 * Keep the Meal Prep menu highlighted when editing ingredients or their taxonomies.
	 */
	public function highlight_parent( string $parent_file ): string {
		global $current_screen;
		if ( ! $current_screen ) {
			return $parent_file;
		}
		if ( Ingredient::POST_TYPE === ( $current_screen->post_type ?? '' ) ) {
			return self::SLUG;
		}
		if ( in_array( $current_screen->taxonomy ?? '', [ IngredientType::TAXONOMY, Allergen::TAXONOMY ], true ) ) {
			return self::SLUG;
		}
		return $parent_file;
	}
}

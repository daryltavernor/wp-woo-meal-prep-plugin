<?php
/**
 * Registers the plugin's admin menu structure under WooCommerce.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

final class MenuRegistry {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ], 60 );
	}

	public function add_pages(): void {
		$parent = 'woocommerce';

		add_submenu_page(
			$parent,
			__( 'Meal Prep Dashboard', 'fastnutrition-mealprep' ),
			__( 'Meal Prep', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-prep-dashboard',
			[ \FastNutrition\MealPrep\Plugin::instance()->service( 'prep_dashboard' ), 'render' ]
		);
		add_submenu_page(
			$parent,
			__( 'Printable Prep Sheet', 'fastnutrition-mealprep' ),
			__( 'Prep Sheet', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-prep-sheet',
			[ \FastNutrition\MealPrep\Plugin::instance()->service( 'prep_sheet' ), 'render' ]
		);
		add_submenu_page(
			$parent,
			__( 'Delivery & Collection Profiles', 'fastnutrition-mealprep' ),
			__( 'Delivery Profiles', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-profiles',
			[ \FastNutrition\MealPrep\Plugin::instance()->service( 'profile_admin' ), 'render' ]
		);
		add_submenu_page(
			$parent,
			__( 'Blocked Dates', 'fastnutrition-mealprep' ),
			__( 'Blocked Dates', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-blocked-dates',
			[ \FastNutrition\MealPrep\Plugin::instance()->service( 'blocked_admin' ), 'render' ]
		);
	}
}

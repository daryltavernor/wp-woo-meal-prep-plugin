<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

final class MenuRegistry {

	public const SLUG = 'fn-mealprep';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 50 );
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

		add_submenu_page(
			self::SLUG,
			__( 'Prep Dashboard', 'fastnutrition-mealprep' ),
			__( 'Prep Dashboard', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			self::SLUG,
			[ PrepDashboard::class, 'render_static' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Printable Prep Sheet', 'fastnutrition-mealprep' ),
			__( 'Prep Sheet', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-prep-sheet',
			[ PrepSheet::class, 'render_static' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Delivery Profiles', 'fastnutrition-mealprep' ),
			__( 'Delivery Profiles', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-delivery-profiles',
			[ ProfileAdmin::class, 'render_static' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Blocked Dates', 'fastnutrition-mealprep' ),
			__( 'Blocked Dates', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			'fn-blocked-dates',
			[ BlockedDatesAdmin::class, 'render_static' ]
		);
	}
}

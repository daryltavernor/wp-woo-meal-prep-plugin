<?php
/**
 * Plugin Name: Fast Nutrition — Meal Prep
 * Plugin URI:  https://www.fastnutrition.co.uk
 * Description: Full meal-prep ordering system for WooCommerce: meal builder, macros, bundles, add-ons, delivery/collection profiles, multi-step Blocks checkout, and kitchen prep management.
 * Version:     1.7.22
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * WC requires at least: 9.4
 * WC tested up to: 9.6
 * Author:      Fast Nutrition
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fastnutrition-mealprep
 * Domain Path: /languages
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'FN_MEALPREP_FILE', __FILE__ );
define( 'FN_MEALPREP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FN_MEALPREP_URL', plugin_dir_url( __FILE__ ) );
define( 'FN_MEALPREP_VERSION', '1.7.22' );

$fn_autoload = FN_MEALPREP_DIR . 'vendor/autoload.php';
if ( ! is_readable( $fn_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Fast Nutrition Meal Prep: composer dependencies are missing. Run "composer install" in the plugin directory.', 'fastnutrition-mealprep' );
			echo '</p></div>';
		}
	);
	return;
}
require $fn_autoload;

// GitHub-backed self-updates from WP dashboard.
if ( ! defined( 'FN_MEALPREP_GITHUB_REPO' ) ) {
	define( 'FN_MEALPREP_GITHUB_REPO', 'https://github.com/daryltavernor/wp-woo-meal-prep-plugin/' );
}
if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	$fn_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		FN_MEALPREP_GITHUB_REPO,
		FN_MEALPREP_FILE,
		'fastnutrition-mealprep'
	);
	// Allow the branch + private-repo token to be configured from the Settings page (or via constants).
	$fn_branch = defined( 'FN_MEALPREP_UPDATE_BRANCH' )
		? FN_MEALPREP_UPDATE_BRANCH
		: ( get_option( 'fn_update_branch' ) ?: 'main' );
	$fn_update_checker->setBranch( (string) $fn_branch );

	$fn_token = defined( 'FN_MEALPREP_GITHUB_TOKEN' )
		? FN_MEALPREP_GITHUB_TOKEN
		: (string) get_option( 'fn_update_token', '' );
	if ( $fn_token ) {
		$fn_update_checker->setAuthentication( $fn_token );
	}
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FN_MEALPREP_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FN_MEALPREP_FILE, true );
		}
	}
);

register_activation_hook( FN_MEALPREP_FILE, [ \FastNutrition\MealPrep\Install\Activator::class, 'activate' ] );
register_deactivation_hook( FN_MEALPREP_FILE, [ \FastNutrition\MealPrep\Install\Activator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Fast Nutrition Meal Prep requires WooCommerce to be installed and active.', 'fastnutrition-mealprep' );
					echo '</p></div>';
				}
			);
			return;
		}
		\FastNutrition\MealPrep\Install\Activator::maybe_migrate();
		\FastNutrition\MealPrep\Plugin::instance()->boot();
	}
);

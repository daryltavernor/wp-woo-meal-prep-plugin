<?php
/**
 * Plugin Name: Fast Nutrition — Meal Prep
 * Plugin URI:  https://www.fastnutrition.co.uk
 * Description: Meal-builder, bundle pricing, add-ons, delivery/collection profiles, multi-step checkout and kitchen prep management for Fast Nutrition.
 * Version:     0.1.0
 * Author:      Fast Nutrition
 * Text Domain: fastnutrition-mealprep
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.6
 * WC requires at least: 9.4
 * WC tested up to: 9.9
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'FN_MEALPREP_VERSION', '0.1.0' );
define( 'FN_MEALPREP_FILE', __FILE__ );
define( 'FN_MEALPREP_PATH', plugin_dir_path( __FILE__ ) );
define( 'FN_MEALPREP_URL', plugin_dir_url( __FILE__ ) );

$autoload = FN_MEALPREP_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'FastNutrition\\MealPrep\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = FN_MEALPREP_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \FastNutrition\MealPrep\Install\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \FastNutrition\MealPrep\Install\Activator::class, 'deactivate' ] );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FN_MEALPREP_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FN_MEALPREP_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Fast Nutrition Meal Prep requires WooCommerce to be active.', 'fastnutrition-mealprep' ) . '</p></div>';
				}
			);
			return;
		}
		load_plugin_textdomain( 'fastnutrition-mealprep', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		\FastNutrition\MealPrep\Plugin::instance()->boot();
	}
);

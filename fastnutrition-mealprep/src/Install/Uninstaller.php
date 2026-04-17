<?php
/**
 * Uninstall handler — removes custom tables and options when the user explicitly removes the plugin with data purge enabled.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Install;

final class Uninstaller {

	public static function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		$settings = get_option( 'fn_mealprep_settings', [] );
		if ( empty( $settings['purge_on_uninstall'] ) ) {
			return;
		}

		global $wpdb;
		$tables = [
			Activator::table_profiles(),
			Activator::table_blocked_dates(),
			Activator::table_prep_cache(),
		];
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( 'fn_mealprep_settings' );
		delete_option( 'fn_mealprep_db_version' );
	}
}

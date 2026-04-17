<?php
/**
 * Creates custom tables and seeds default options on plugin activation.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Install;

final class Activator {

	public const DB_VERSION = '1';

	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function table_profiles(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_delivery_profiles';
	}

	public static function table_blocked_dates(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_blocked_dates';
	}

	public static function table_prep_cache(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_prep_cache';
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$profiles = self::table_profiles();
		$blocked  = self::table_blocked_dates();
		$prep     = self::table_prep_cache();

		$sql = [];

		$sql[] = "CREATE TABLE {$profiles} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			method VARCHAR(20) NOT NULL DEFAULT 'delivery',
			days_mask TINYINT UNSIGNED NOT NULL DEFAULT 0,
			slots LONGTEXT NULL,
			postcodes LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			priority INT NOT NULL DEFAULT 10,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY method (method),
			KEY active (active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$blocked} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blocked_date DATE NOT NULL,
			reason VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY blocked_date (blocked_date)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prep} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfilment_date DATE NOT NULL,
			method VARCHAR(20) NOT NULL,
			ingredient_id BIGINT UNSIGNED NOT NULL,
			ingredient_type VARCHAR(32) NOT NULL,
			portions INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY date_method_ing (fulfilment_date, method, ingredient_id),
			KEY fulfilment_date (fulfilment_date)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'fn_mealprep_db_version', self::DB_VERSION, false );
	}

	private static function seed_options(): void {
		if ( false === get_option( 'fn_mealprep_settings' ) ) {
			add_option(
				'fn_mealprep_settings',
				[
					'slot_window_days'    => 14,
					'min_lead_hours'      => 24,
					'macros_in_emails'    => true,
					'enable_favourites'   => true,
					'pdf_footer_note'     => '',
				],
				'',
				false
			);
		}
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Install;

use FastNutrition\MealPrep\FoodSafety\FoodSafetySettings;
use FastNutrition\MealPrep\Stats\PopularCombos;
use FastNutrition\MealPrep\Stats\StatsRollup;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class Activator {

	// 1.5.0: adds fn_prep_records + fn_daily_checks (food-safety prep records).
	// 1.4.1: no schema change — bumped so maybe_migrate() queues a PopularCombos
	// recompute, regenerating the stored rankings with per-tier top lists.
	public const DB_VERSION = '1.5.0';

	/** Token for the one-off re-roll when the stats aggregation rules changed. */
	private const STATS_REAGG_TOKEN = 'meals_zones_v2';

	public static function activate(): void {
		self::create_tables();
		( new IngredientType() )->register_taxonomy();
		( new Allergen() )->register_taxonomy();
		self::seed_ingredient_types();
		IngredientSeeder::seed();
		update_option( 'fn_mealprep_db_version', self::DB_VERSION, false );
		PopularCombos::ensure_scheduled();
		PopularCombos::queue_recompute();
		StatsRollup::ensure_scheduled();
		StatsRollup::ensure_backfilled();
		// Fresh install already builds with the current rules — mark the re-roll
		// token done so a later upgrade doesn't pointlessly re-aggregate.
		update_option( 'fn_stats_reagg_' . self::STATS_REAGG_TOKEN, 1, false );
		flush_rewrite_rules();
	}

	public static function maybe_migrate(): void {
		$installed = (string) get_option( 'fn_mealprep_db_version', '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		PopularCombos::ensure_scheduled();
		PopularCombos::queue_recompute();
		StatsRollup::ensure_scheduled();
		StatsRollup::ensure_backfilled();
		// Existing installs: re-roll history once so sweets count as meals and the
		// delivery-zone figures populate.
		StatsRollup::ensure_reaggregated( self::STATS_REAGG_TOKEN );
		update_option( 'fn_mealprep_db_version', self::DB_VERSION, false );
	}

	public static function deactivate(): void {
		PopularCombos::unschedule();
		StatsRollup::unschedule();
		FoodSafetySettings::unschedule();
		flush_rewrite_rules();
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$profiles        = $wpdb->prefix . 'fn_delivery_profiles';
		$blocked         = $wpdb->prefix . 'fn_blocked_dates';
		$prep_cache      = $wpdb->prefix . 'fn_prep_cache';
		$daily_stats     = $wpdb->prefix . 'fn_daily_stats';
		$daily_ing       = $wpdb->prefix . 'fn_daily_ingredient_stats';
		$daily_meal      = $wpdb->prefix . 'fn_daily_meal_stats';
		$daily_zone      = $wpdb->prefix . 'fn_daily_zone_stats';
		$prep_records    = $wpdb->prefix . 'fn_prep_records';
		$daily_checks    = $wpdb->prefix . 'fn_daily_checks';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$profiles} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				method varchar(20) NOT NULL DEFAULT 'delivery',
				days tinyint(3) unsigned NOT NULL DEFAULT 0,
				slots longtext NULL,
				postcodes longtext NULL,
				zone_ids longtext NULL,
				priority int(11) NOT NULL DEFAULT 10,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY method (method),
				KEY active (active)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$blocked} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blocked_date date NOT NULL,
				reason varchar(255) NULL,
				created_by bigint(20) unsigned NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY blocked_date (blocked_date)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prep_cache} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fulfilment_date date NOT NULL,
				ingredient_id bigint(20) unsigned NOT NULL,
				portion_count int(11) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_ingredient (fulfilment_date, ingredient_id),
				KEY fulfilment_date (fulfilment_date)
			) {$charset_collate};"
		);

		// Permanent fulfilment rollup ledger (kept indefinitely for trend reports).
		dbDelta(
			"CREATE TABLE {$daily_stats} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				stat_date date NOT NULL,
				method varchar(20) NOT NULL DEFAULT '',
				orders int(11) NOT NULL DEFAULT 0,
				meals int(11) NOT NULL DEFAULT 0,
				sweets int(11) NOT NULL DEFAULT 0,
				addons int(11) NOT NULL DEFAULT 0,
				items_total int(11) NOT NULL DEFAULT 0,
				revenue decimal(12,2) NOT NULL DEFAULT 0.00,
				meals_build int(11) NOT NULL DEFAULT 0,
				meals_set int(11) NOT NULL DEFAULT 0,
				meals_standalone int(11) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_method (stat_date, method),
				KEY stat_date (stat_date)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$daily_ing} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				stat_date date NOT NULL,
				ingredient_id bigint(20) unsigned NOT NULL,
				portions int(11) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_ingredient (stat_date, ingredient_id),
				KEY ingredient_id (ingredient_id),
				KEY stat_date (stat_date)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$daily_meal} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				stat_date date NOT NULL,
				meal_key varchar(191) NOT NULL DEFAULT '',
				mode varchar(20) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_meal (stat_date, meal_key),
				KEY meal_key (meal_key),
				KEY stat_date (stat_date)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$daily_zone} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				stat_date date NOT NULL,
				zone_key varchar(20) NOT NULL DEFAULT '',
				orders int(11) NOT NULL DEFAULT 0,
				meals int(11) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_zone (stat_date, zone_key),
				KEY zone_key (zone_key),
				KEY stat_date (stat_date)
			) {$charset_collate};"
		);

		// Food-safety prep records: one row per cooked batch per day (Phase 2 of
		// the Cook & Pack Record). Sample rows seeded for demonstration are
		// flagged with is_sample so they can be excluded or removed.
		dbDelta(
			"CREATE TABLE {$prep_records} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				record_date date NOT NULL,
				batch_code varchar(40) NOT NULL,
				ingredient_id bigint(20) unsigned NOT NULL DEFAULT 0,
				component varchar(191) NOT NULL DEFAULT '',
				method varchar(20) NOT NULL DEFAULT '',
				portions int(11) NOT NULL DEFAULT 0,
				target_c decimal(5,1) NULL,
				time_in varchar(5) NOT NULL DEFAULT '',
				time_out varchar(5) NOT NULL DEFAULT '',
				probe1_c decimal(5,1) NULL,
				probe2_c decimal(5,1) NULL,
				cooled_by varchar(5) NOT NULL DEFAULT '',
				fridge_unit varchar(100) NOT NULL DEFAULT '',
				packed_at varchar(5) NOT NULL DEFAULT '',
				use_by date NULL,
				initials varchar(10) NOT NULL DEFAULT '',
				corrective_action text NULL,
				out_of_limit tinyint(1) NOT NULL DEFAULT 0,
				is_sample tinyint(1) NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_batch (record_date, batch_code),
				KEY ingredient_id (ingredient_id),
				KEY record_date (record_date),
				KEY is_sample (is_sample)
			) {$charset_collate};"
		);

		// The day's checks (fridge temperatures, probe calibration, opening and
		// closing, sign-off) as key/value rows.
		dbDelta(
			"CREATE TABLE {$daily_checks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				check_date date NOT NULL,
				check_key varchar(120) NOT NULL,
				check_value varchar(191) NOT NULL DEFAULT '',
				is_sample tinyint(1) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY date_key (check_date, check_key),
				KEY check_date (check_date)
			) {$charset_collate};"
		);
	}

	private static function seed_ingredient_types(): void {
		$terms = [
			'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
			'sweet'    => __( 'Sweet', 'fastnutrition-mealprep' ),
		];

		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, IngredientType::TAXONOMY ) ) {
				wp_insert_term( $name, IngredientType::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		update_option( 'fn_ingredient_types_seeded', 1, false );
	}
}

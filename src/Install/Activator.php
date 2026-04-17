<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Install;

use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class Activator {

	public const DB_VERSION = '1.1.0';

	public static function activate(): void {
		self::create_tables();
		( new IngredientType() )->register_taxonomy();
		( new Allergen() )->register_taxonomy();
		self::seed_ingredient_types();
		update_option( 'fn_mealprep_db_version', self::DB_VERSION, false );
		flush_rewrite_rules();
	}

	public static function maybe_migrate(): void {
		$installed = (string) get_option( 'fn_mealprep_db_version', '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		update_option( 'fn_mealprep_db_version', self::DB_VERSION, false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$profiles        = $wpdb->prefix . 'fn_delivery_profiles';
		$blocked         = $wpdb->prefix . 'fn_blocked_dates';
		$prep_cache      = $wpdb->prefix . 'fn_prep_cache';

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
	}

	private static function seed_ingredient_types(): void {
		$terms = [
			'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
		];

		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, IngredientType::TAXONOMY ) ) {
				wp_insert_term( $name, IngredientType::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		update_option( 'fn_ingredient_types_seeded', 1, false );
	}
}

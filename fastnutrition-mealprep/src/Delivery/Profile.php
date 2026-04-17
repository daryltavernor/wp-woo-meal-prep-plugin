<?php
/**
 * Delivery / collection profile data access.
 * A profile groups a set of postcodes (with wildcards like ST10*), days of week, time slots, and method.
 *
 * Backed by the custom table wp_fn_delivery_profiles.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use FastNutrition\MealPrep\Install\Activator;

final class Profile {

	public const METHOD_DELIVERY   = 'delivery';
	public const METHOD_COLLECTION = 'collection';

	public const DAY_MON = 1;
	public const DAY_TUE = 2;
	public const DAY_WED = 4;
	public const DAY_THU = 8;
	public const DAY_FRI = 16;
	public const DAY_SAT = 32;
	public const DAY_SUN = 64;

	public function register(): void {
		// Data-layer only. CRUD UI lives in Admin\ProfileAdmin.
	}

	/**
	 * @return array<int,array{id:int,name:string,method:string,days_mask:int,slots:array<int,array{start:string,end:string,capacity:?int}>,postcodes:array<int,string>,active:bool,priority:int}>
	 */
	public static function all( bool $only_active = false ): array {
		global $wpdb;
		$table = Activator::table_profiles();
		$where = $only_active ? ' WHERE active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY priority ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Activator::table_profiles() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function save( array $data ): int {
		global $wpdb;

		$row = [
			'name'      => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'method'    => in_array( $data['method'] ?? '', [ self::METHOD_DELIVERY, self::METHOD_COLLECTION ], true ) ? $data['method'] : self::METHOD_DELIVERY,
			'days_mask' => (int) ( $data['days_mask'] ?? 0 ) & 0x7F,
			'slots'     => wp_json_encode( self::sanitize_slots( (array) ( $data['slots'] ?? [] ) ) ),
			'postcodes' => wp_json_encode( self::sanitize_postcodes( (array) ( $data['postcodes'] ?? [] ) ) ),
			'active'    => ! empty( $data['active'] ) ? 1 : 0,
			'priority'  => (int) ( $data['priority'] ?? 10 ),
		];

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $id > 0 ) {
			$wpdb->update( Activator::table_profiles(), $row, [ 'id' => $id ] );
			return $id;
		}
		$wpdb->insert( Activator::table_profiles(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Activator::table_profiles(), [ 'id' => $id ], [ '%d' ] );
	}

	private static function hydrate( array $row ): array {
		$slots     = json_decode( (string) ( $row['slots'] ?? '[]' ), true );
		$postcodes = json_decode( (string) ( $row['postcodes'] ?? '[]' ), true );
		return [
			'id'        => (int) $row['id'],
			'name'      => (string) $row['name'],
			'method'    => (string) $row['method'],
			'days_mask' => (int) $row['days_mask'],
			'slots'     => is_array( $slots ) ? $slots : [],
			'postcodes' => is_array( $postcodes ) ? $postcodes : [],
			'active'    => (bool) $row['active'],
			'priority'  => (int) $row['priority'],
		];
	}

	private static function sanitize_slots( array $input ): array {
		$out = [];
		foreach ( $input as $slot ) {
			$start = isset( $slot['start'] ) ? self::normalize_time( (string) $slot['start'] ) : null;
			$end   = isset( $slot['end'] ) ? self::normalize_time( (string) $slot['end'] ) : null;
			if ( ! $start || ! $end ) {
				continue;
			}
			$capacity = isset( $slot['capacity'] ) && '' !== $slot['capacity'] ? max( 0, (int) $slot['capacity'] ) : null;
			$out[] = [ 'start' => $start, 'end' => $end, 'capacity' => $capacity ];
		}
		return $out;
	}

	private static function normalize_time( string $value ): ?string {
		if ( ! preg_match( '/^([0-1]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return null;
		}
		return $value;
	}

	private static function sanitize_postcodes( array $input ): array {
		$out = [];
		foreach ( $input as $pc ) {
			$pc = strtoupper( preg_replace( '/\s+/', '', (string) $pc ) );
			$pc = preg_replace( '/[^A-Z0-9\*]/', '', $pc ?: '' );
			if ( $pc ) {
				$out[] = $pc;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function all_days(): array {
		return [
			self::DAY_MON => __( 'Monday', 'fastnutrition-mealprep' ),
			self::DAY_TUE => __( 'Tuesday', 'fastnutrition-mealprep' ),
			self::DAY_WED => __( 'Wednesday', 'fastnutrition-mealprep' ),
			self::DAY_THU => __( 'Thursday', 'fastnutrition-mealprep' ),
			self::DAY_FRI => __( 'Friday', 'fastnutrition-mealprep' ),
			self::DAY_SAT => __( 'Saturday', 'fastnutrition-mealprep' ),
			self::DAY_SUN => __( 'Sunday', 'fastnutrition-mealprep' ),
		];
	}

	/** PHP's `date('N')` returns 1–7 with 1=Mon, 7=Sun. Map to our bitmask flags. */
	public static function php_day_to_mask( int $n ): int {
		static $map = [ 1 => self::DAY_MON, 2 => self::DAY_TUE, 3 => self::DAY_WED, 4 => self::DAY_THU, 5 => self::DAY_FRI, 6 => self::DAY_SAT, 7 => self::DAY_SUN ];
		return $map[ $n ] ?? 0;
	}
}

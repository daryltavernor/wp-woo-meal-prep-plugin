<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

final class Profile {

	public const METHOD_DELIVERY   = 'delivery';
	public const METHOD_COLLECTION = 'collection';

	public const DAY_MON = 0b00000001;
	public const DAY_TUE = 0b00000010;
	public const DAY_WED = 0b00000100;
	public const DAY_THU = 0b00001000;
	public const DAY_FRI = 0b00010000;
	public const DAY_SAT = 0b00100000;
	public const DAY_SUN = 0b01000000;

	public function register(): void {
		// Registration placeholder — the Profile class is primarily a data gateway.
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_delivery_profiles';
	}

	public static function all( ?bool $active_only = true ): array {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}
		$sql .= ' ORDER BY priority ASC, id ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], is_array( $rows ) ? $rows : [] );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	public static function save( array $data ): int {
		global $wpdb;
		$table = self::table();

		$row = [
			'name'      => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'method'    => in_array( $data['method'] ?? '', [ self::METHOD_DELIVERY, self::METHOD_COLLECTION ], true ) ? $data['method'] : self::METHOD_DELIVERY,
			'days'      => (int) ( $data['days'] ?? 0 ),
			'slots'     => wp_json_encode( is_array( $data['slots'] ?? null ) ? $data['slots'] : [] ),
			'postcodes' => wp_json_encode( is_array( $data['postcodes'] ?? null ) ? array_values( array_filter( $data['postcodes'] ) ) : [] ),
			'zone_ids'  => wp_json_encode( is_array( $data['zone_ids'] ?? null ) ? array_values( array_filter( array_map( 'intval', $data['zone_ids'] ) ) ) : [] ),
			'priority'  => (int) ( $data['priority'] ?? 10 ),
			'active'    => ! empty( $data['active'] ) ? 1 : 0,
		];

		if ( ! empty( $data['id'] ) ) {
			$wpdb->update( $table, $row, [ 'id' => (int) $data['id'] ], [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' ], [ '%d' ] );
			return (int) $data['id'];
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $row, [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );
		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
	}

	public static function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'method'     => (string) $row['method'],
			'days'       => (int) $row['days'],
			'slots'      => self::decode_json( $row['slots'] ?? '' ),
			'postcodes'  => self::decode_json( $row['postcodes'] ?? '' ),
			'zone_ids'   => array_map( 'intval', self::decode_json( $row['zone_ids'] ?? '' ) ),
			'priority'   => (int) $row['priority'],
			'active'     => (bool) $row['active'],
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		];
	}

	/**
	 * Extracts the postcode patterns stored against a WooCommerce shipping zone.
	 *
	 * @return array<int,string>
	 */
	public static function zone_postcodes( int $zone_id ): array {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return [];
		}
		$zone = \WC_Shipping_Zones::get_zone( $zone_id );
		if ( ! $zone ) {
			return [];
		}
		$out = [];
		foreach ( (array) $zone->get_zone_locations() as $location ) {
			if ( is_object( $location ) && 'postcode' === ( $location->type ?? '' ) ) {
				$out[] = (string) $location->code;
			}
		}
		return $out;
	}

	/**
	 * Returns all postcode patterns the profile covers, combining manual entries
	 * with those pulled from the linked shipping zones.
	 *
	 * @return array<int,string>
	 */
	public static function effective_postcodes( array $profile ): array {
		$all = is_array( $profile['postcodes'] ?? null ) ? $profile['postcodes'] : [];
		foreach ( (array) ( $profile['zone_ids'] ?? [] ) as $zid ) {
			$all = array_merge( $all, self::zone_postcodes( (int) $zid ) );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $all ) ) ) );
	}

	private static function decode_json( string $json ): array {
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function day_to_mask( int $weekday ): int {
		// weekday 0 = Sunday (per PHP `date('w')`).
		$map = [
			0 => self::DAY_SUN,
			1 => self::DAY_MON,
			2 => self::DAY_TUE,
			3 => self::DAY_WED,
			4 => self::DAY_THU,
			5 => self::DAY_FRI,
			6 => self::DAY_SAT,
		];
		return $map[ $weekday ] ?? 0;
	}
}

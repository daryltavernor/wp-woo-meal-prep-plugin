<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

final class ProfileResolver {

	public function register(): void {
		// No hooks needed; used directly by SlotAvailability and admin.
	}

	public static function match_postcode( string $postcode, ?string $method = null ): array {
		$postcode = self::normalize( $postcode );
		$results  = [];
		// Delivery (and postcode-scoped) matching only runs when we have a
		// postcode. Collection profiles are added unconditionally below, so an
		// empty postcode still yields the collection options (the offline Quick
		// Order tool collects no address for collection orders).
		if ( '' !== $postcode ) {
			foreach ( Profile::all( true ) as $profile ) {
				if ( $method && $profile['method'] !== $method && $profile['method'] !== 'collection' ) {
					continue;
				}
				if ( self::postcode_matches( $postcode, Profile::effective_postcodes( $profile ) ) ) {
					$results[] = $profile;
				}
			}
		}
		// Collection profiles always apply regardless of postcode.
		foreach ( Profile::all( true ) as $profile ) {
			if ( Profile::METHOD_COLLECTION === $profile['method'] && ! in_array( $profile, $results, true ) ) {
				$results[] = $profile;
			}
		}
		return $results;
	}

	public static function postcode_matches( string $postcode, array $patterns ): bool {
		$postcode = self::normalize_postcode( $postcode );
		if ( '' === $postcode ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = self::normalize_pattern( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( str_contains( $pattern, '*' ) ) {
				// Wildcard pattern. The literal space between the outward
				// code and the * (e.g. "ST1 *") is meaningful and must be
				// preserved — without it, "ST1*" would loose-match "ST10".
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
				if ( preg_match( $regex, $postcode ) ) {
					return true;
				}
				continue;
			}
			// Non-wildcard: match exactly, or match as the outward code of
			// a full postcode (pattern + space + inward). Reject the loose
			// prefix case so "ST1" doesn't match "ST10 4AB".
			if ( $postcode === $pattern || str_starts_with( $postcode, $pattern . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalise a customer's postcode for matching. Trims, uppercases,
	 * strips internal whitespace, then inserts the standard UK single
	 * space before the last three characters when the result is long
	 * enough to be a full postcode. Matches the format WC uses internally
	 * (`wc_format_postcode` for GB).
	 */
	public static function normalize_postcode( string $postcode ): string {
		$val = strtoupper( preg_replace( '/\s+/', '', trim( $postcode ) ) ?? '' );
		if ( strlen( $val ) >= 5 ) {
			$val = substr( $val, 0, -3 ) . ' ' . substr( $val, -3 );
		}
		return $val;
	}

	/**
	 * Normalise a stored pattern. Trims, uppercases, and collapses runs
	 * of internal whitespace to a single space — but PRESERVES that space.
	 * In a UK postcode pattern like "ST1 *", the space sits between the
	 * outward code and the wildcard and is the only thing stopping "ST10"
	 * from looking like a member of the ST1 area.
	 */
	public static function normalize_pattern( string $pattern ): string {
		return strtoupper( preg_replace( '/\s+/', ' ', trim( $pattern ) ) ?? '' );
	}

	/**
	 * Backwards-compatible alias. Callers that have a customer-entered
	 * postcode should prefer normalize_postcode() directly.
	 */
	public static function normalize( string $postcode ): string {
		return self::normalize_postcode( $postcode );
	}

	/**
	 * Finds conflicts across profiles: postcode patterns that overlap between
	 * different delivery profiles, and WooCommerce shipping zones with no profile.
	 *
	 * @return array{overlaps:array<int,array{postcode:string,profile_ids:array<int,int>}>,zones_without_profile:array<int,string>}
	 */
	public static function conflicts(): array {
		$profiles = Profile::all( false );
		$map      = [];
		foreach ( $profiles as $p ) {
			if ( Profile::METHOD_DELIVERY !== $p['method'] ) {
				continue;
			}
			foreach ( Profile::effective_postcodes( $p ) as $pc ) {
				$key = self::normalize_pattern( (string) $pc );
				if ( '' === $key ) {
					continue;
				}
				$map[ $key ][] = (int) $p['id'];
			}
		}
		$overlaps = [];
		foreach ( $map as $pc => $ids ) {
			if ( count( $ids ) > 1 ) {
				$overlaps[] = [ 'postcode' => $pc, 'profile_ids' => array_values( array_unique( $ids ) ) ];
			}
		}

		$zones_without_profile = [];
		if ( function_exists( 'WC' ) && class_exists( \WC_Shipping_Zones::class ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			foreach ( $zones as $zone ) {
				$has_match = false;
				$postcodes_in_zone = [];
				foreach ( (array) ( $zone['zone_locations'] ?? [] ) as $location ) {
					if ( is_object( $location ) && 'postcode' === ( $location->type ?? '' ) ) {
						$postcodes_in_zone[] = (string) $location->code;
					}
				}
				foreach ( $postcodes_in_zone as $pc ) {
					$zone_pat = self::normalize_pattern( $pc );
					if ( '' === $zone_pat ) {
						continue;
					}
					foreach ( $profiles as $p ) {
						foreach ( Profile::effective_postcodes( $p ) as $profile_pc ) {
							if ( $zone_pat === self::normalize_pattern( (string) $profile_pc ) ) {
								$has_match = true;
								break 3;
							}
						}
					}
				}
				if ( ! $has_match && ! empty( $postcodes_in_zone ) ) {
					$zones_without_profile[] = (string) ( $zone['zone_name'] ?? '' );
				}
			}
		}

		return [
			'overlaps'              => $overlaps,
			'zones_without_profile' => $zones_without_profile,
		];
	}
}

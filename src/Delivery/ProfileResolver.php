<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

final class ProfileResolver {

	public function register(): void {
		// No hooks needed; used directly by SlotAvailability and admin.
	}

	public static function match_postcode( string $postcode, ?string $method = null ): array {
		$postcode = self::normalize( $postcode );
		if ( '' === $postcode ) {
			return [];
		}
		$results = [];
		foreach ( Profile::all( true ) as $profile ) {
			if ( $method && $profile['method'] !== $method && $profile['method'] !== 'collection' ) {
				continue;
			}
			if ( self::postcode_matches( $postcode, Profile::effective_postcodes( $profile ) ) ) {
				$results[] = $profile;
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
		$postcode = self::normalize( $postcode );
		foreach ( $patterns as $pattern ) {
			$pattern = self::normalize( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( str_contains( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
				if ( preg_match( $regex, $postcode ) ) {
					return true;
				}
				continue;
			}
			// Prefix match (e.g. "ST10" matches "ST10 4AB").
			if ( str_starts_with( $postcode, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	public static function normalize( string $postcode ): string {
		return strtoupper( preg_replace( '/\s+/', '', trim( $postcode ) ) ?? '' );
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
				$key = self::normalize( (string) $pc );
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
					foreach ( $profiles as $p ) {
						if ( self::postcode_matches( $pc, Profile::effective_postcodes( $p ) ) ) {
							$has_match = true;
							break 2;
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

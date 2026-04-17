<?php
/**
 * Resolves a postcode to the matching profile(s), and detects conflicts / uncovered postcodes vs
 * the configured WooCommerce shipping zones.
 *
 * Postcode matching supports two forms:
 *   - exact: "ST101AA"
 *   - wildcard prefix: "ST10*" (matches any postcode starting with ST10)
 *
 * Lowest priority number wins when multiple profiles match (same semantics as WC shipping zones).
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

final class ProfileResolver {

	public function register(): void {
		// Pure service; no hooks.
	}

	/**
	 * Return matching profiles for a postcode, sorted by priority ascending.
	 *
	 * @return array<int,array>
	 */
	public function resolve( string $postcode, ?string $method = null ): array {
		$needle = $this->normalize( $postcode );
		if ( '' === $needle ) {
			return [];
		}
		$matches = [];
		foreach ( Profile::all( true ) as $profile ) {
			if ( $method && $profile['method'] !== $method ) {
				continue;
			}
			if ( $this->postcode_matches( $needle, $profile['postcodes'] ) ) {
				$matches[] = $profile;
			}
		}
		usort( $matches, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		return $matches;
	}

	/**
	 * Detect conflicting profiles (postcodes matched by > 1 active profile of the same method,
	 * ignoring priority). Returns a list of { postcode_pattern, profile_ids }.
	 */
	public function conflicts(): array {
		$profiles = Profile::all( true );
		$by_pattern = [];
		foreach ( $profiles as $p ) {
			foreach ( $p['postcodes'] as $pattern ) {
				$key = $p['method'] . '|' . $pattern;
				$by_pattern[ $key ][] = [ 'id' => $p['id'], 'name' => $p['name'], 'method' => $p['method'] ];
			}
		}
		$conflicts = [];
		foreach ( $by_pattern as $key => $profiles_list ) {
			if ( count( $profiles_list ) > 1 ) {
				[ $method, $pattern ] = explode( '|', $key, 2 );
				$conflicts[] = [
					'method'   => $method,
					'pattern'  => $pattern,
					'profiles' => $profiles_list,
				];
			}
		}
		return $conflicts;
	}

	/**
	 * Walk every WooCommerce shipping zone and flag postcodes that are not covered by any active profile.
	 *
	 * @return array<int,array{zone:string,pattern:string}>
	 */
	public function uncovered_shipping_postcodes(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return [];
		}
		$out = [];
		$profiles = Profile::all( true );
		foreach ( \WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone_name = $zone_data['zone_name'] ?? '';
			foreach ( ( $zone_data['zone_locations'] ?? [] ) as $loc ) {
				if ( empty( $loc->type ) || 'postcode' !== $loc->type ) {
					continue;
				}
				$pc = $this->normalize( (string) $loc->code );
				if ( '' === $pc ) {
					continue;
				}
				$covered = false;
				foreach ( $profiles as $p ) {
					if ( $this->postcode_matches( $pc, $p['postcodes'] ) ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered ) {
					$out[] = [ 'zone' => $zone_name, 'pattern' => $pc ];
				}
			}
		}
		return $out;
	}

	private function normalize( string $postcode ): string {
		$upper = strtoupper( preg_replace( '/\s+/', '', $postcode ) );
		return preg_replace( '/[^A-Z0-9]/', '', $upper ?: '' ) ?: '';
	}

	private function postcode_matches( string $needle, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( str_ends_with( $pattern, '*' ) ) {
				$prefix = substr( $pattern, 0, -1 );
				if ( '' !== $prefix && str_starts_with( $needle, $prefix ) ) {
					return true;
				}
				continue;
			}
			if ( $needle === $pattern ) {
				return true;
			}
		}
		return false;
	}
}

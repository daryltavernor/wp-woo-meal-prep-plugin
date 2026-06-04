<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * Resolves a submitted staff PIN to the named staff member it belongs to.
 *
 * PINs are stored hashed (see InStoreSettings::save_staff()). Resolution checks
 * the submitted PIN against every stored hash and returns the matching staff
 * record, which the order builder stamps on the order for attribution.
 */
final class StaffPins {

	/**
	 * @param string $pin Digits entered on the screen.
	 * @return array{id:int,name:string}|null Matching staff, or null if no match.
	 */
	public static function resolve( string $pin ): ?array {
		$pin = preg_replace( '/\D/', '', $pin );
		if ( '' === $pin ) {
			return null;
		}
		foreach ( InStoreSettings::staff() as $row ) {
			$hash = (string) ( $row['pin_hash'] ?? '' );
			if ( '' !== $hash && wp_check_password( $pin, $hash ) ) {
				return [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ];
			}
		}
		return null;
	}

	public static function any_configured(): bool {
		return ! empty( InStoreSettings::staff() );
	}
}

<?php
/**
 * Blocked-dates store. A row here blocks every profile on the given date.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use FastNutrition\MealPrep\Install\Activator;

final class BlockedDates {

	public function register(): void {
		// Data-layer only.
	}

	/** @return array<int,array{id:int,blocked_date:string,reason:string}> */
	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, blocked_date, reason FROM ' . Activator::table_blocked_dates() . ' ORDER BY blocked_date ASC', ARRAY_A );
		return array_map(
			static fn( array $r ): array => [
				'id'           => (int) $r['id'],
				'blocked_date' => (string) $r['blocked_date'],
				'reason'       => (string) $r['reason'],
			],
			$rows ?: []
		);
	}

	public static function is_blocked( string $date ): bool {
		global $wpdb;
		$out = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Activator::table_blocked_dates() . ' WHERE blocked_date = %s LIMIT 1', $date ) );
		return null !== $out;
	}

	public static function add( string $date, string $reason = '' ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		global $wpdb;
		$result = $wpdb->insert(
			Activator::table_blocked_dates(),
			[
				'blocked_date' => $date,
				'reason'       => sanitize_text_field( $reason ),
				'created_by'   => get_current_user_id(),
			],
			[ '%s', '%s', '%d' ]
		);
		return false !== $result;
	}

	public static function remove( int $id ): void {
		global $wpdb;
		$wpdb->delete( Activator::table_blocked_dates(), [ 'id' => $id ], [ '%d' ] );
	}
}

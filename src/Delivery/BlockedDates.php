<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

final class BlockedDates {

	public function register(): void {
		// Data class only.
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_blocked_dates';
	}

	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY blocked_date ASC', ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	public static function is_blocked( string $date ): bool {
		global $wpdb;
		$date = sanitize_text_field( $date );
		$row  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE blocked_date = %s', $date ) );
		return null !== $row;
	}

	public static function add( string $date, string $reason = '' ): int {
		global $wpdb;
		$wpdb->replace(
			self::table(),
			[
				'blocked_date' => $date,
				'reason'       => sanitize_text_field( $reason ),
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function remove( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
	}
}

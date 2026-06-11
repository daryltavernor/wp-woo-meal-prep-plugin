<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use DateTimeImmutable;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;

final class SlotAvailability {

	public const WINDOW_DAYS = 14;

	/**
	 * The earliest order-creation timestamp that could carry the given fulfilment
	 * date. A customer books at most WINDOW_DAYS ahead and never in the past, so
	 * any order whose fulfilment date is $date was necessarily created within
	 * [$date - WINDOW_DAYS, $date]; the extra margin absorbs cut-off / timezone
	 * slop. Use this as a wc_get_orders() 'date_created' => '>=' bound to keep
	 * date-filtered order scans constant-time instead of walking the whole order
	 * history (the in-PHP date check still makes the result exact).
	 */
	public static function created_since_for_date( string $date ): int {
		return strtotime( $date ) - ( self::WINDOW_DAYS + 21 ) * DAY_IN_SECONDS;
	}

	public function register(): void {
		// Booking counts are cached (see bookings_map()); bust that cache whenever
		// an order is created or changes status so slot capacity stays exact
		// without re-scanning every order on each slot-picker request.
		add_action( 'woocommerce_new_order', [ __CLASS__, 'flush_bookings_cache' ] );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'flush_bookings_cache' ] );
	}

	/**
	 * The earliest date a customer may pick, in WordPress local timezone,
	 * as a 'Y-m-d' string.
	 *
	 * Baseline: tomorrow (one-day lead time).
	 * If a daily cut-off is configured and the current local time is at or
	 * past the cut-off, tomorrow is skipped — earliest becomes day-after-
	 * tomorrow. E.g. cut-off 18:00, 18:01 on Monday → earliest is Wednesday.
	 *
	 * Pass $cutoff_override to use a different cut-off than the configured one
	 * (the in-store tools pass 23:55 so staff can still book tomorrow all
	 * evening). Pass '' to disable the cut-off entirely (earliest = tomorrow).
	 */
	public static function earliest_allowed_date( ?string $cutoff_override = null ): string {
		$now    = new DateTimeImmutable( 'now', wp_timezone() );
		$cutoff = null !== $cutoff_override ? $cutoff_override : SettingsPage::order_cutoff();
		$offset = 1;
		if ( '' !== $cutoff ) {
			[ $h, $m ]    = explode( ':', $cutoff );
			$today_cutoff = $now->setTime( (int) $h, (int) $m, 0 );
			if ( $now >= $today_cutoff ) {
				$offset = 2;
			}
		}
		return $now->modify( "+{$offset} days" )->setTime( 0, 0 )->format( 'Y-m-d' );
	}

	/**
	 * Returns available {date, slot} options for a postcode, optionally filtered by method.
	 *
	 * @param string $postcode
	 * @param string|null $method 'delivery' | 'collection' | null (both)
	 * @param string|null $cutoff_override Optional cut-off override (see earliest_allowed_date()).
	 * @return array<int,array{date:string,day_label:string,method:string,profile_id:int,profile_name:string,slots:array<int,array{start:string,end:string,remaining:int|null}>}>
	 */
	public static function options( string $postcode, ?string $method = null, ?string $cutoff_override = null ): array {
		$postcode = ProfileResolver::normalize( $postcode );
		$profiles = ProfileResolver::match_postcode( $postcode );
		if ( $method ) {
			$profiles = array_values( array_filter( $profiles, static fn( $p ) => $p['method'] === $method ) );
		}
		if ( empty( $profiles ) ) {
			return [];
		}

		$start  = new DateTimeImmutable( self::earliest_allowed_date( $cutoff_override ), wp_timezone() );
		$booked = self::bookings_map();
		$out    = [];

		for ( $i = 0; $i <= self::WINDOW_DAYS; $i++ ) {
			$day     = $start->modify( "+{$i} days" );
			$weekday = (int) $day->format( 'w' );
			$mask    = Profile::day_to_mask( $weekday );
			$date    = $day->format( 'Y-m-d' );
			if ( BlockedDates::is_blocked( $date ) ) {
				continue;
			}
			foreach ( $profiles as $profile ) {
				if ( 0 === ( $profile['days'] & $mask ) ) {
					continue;
				}
				$slots = [];
				foreach ( $profile['slots'] as $slot ) {
					$capacity  = isset( $slot['capacity'] ) ? (int) $slot['capacity'] : null;
					$remaining = null === $capacity ? null : max( 0, $capacity - ( $booked[ (int) $profile['id'] ][ $date ][ (string) $slot['start'] . '|' . (string) $slot['end'] ] ?? 0 ) );
					if ( null !== $remaining && $remaining <= 0 ) {
						continue;
					}
					$slots[] = [
						'start'     => (string) $slot['start'],
						'end'       => (string) $slot['end'],
						'remaining' => $remaining,
					];
				}
				if ( empty( $slots ) ) {
					continue;
				}
				$out[] = [
					'date'         => $date,
					'day_label'    => wp_date( 'D j M', $day->getTimestamp() ),
					'method'       => $profile['method'],
					'profile_id'   => $profile['id'],
					'profile_name' => $profile['name'],
					'slots'        => $slots,
				];
			}
		}
		return $out;
	}

	/**
	 * Booked-slot counts for upcoming orders, as a nested map:
	 *   [ profile_id ][ 'Y-m-d' ][ 'start|end' ] => count.
	 *
	 * Cached until an order is created or changes status (see
	 * flush_bookings_cache()) and — crucially — built from only the RECENT orders
	 * that can still affect upcoming slot capacity, never the full order history.
	 * A customer can only book within [lead time, WINDOW_DAYS], so any order whose
	 * fulfilment date is still in the future was necessarily created within that
	 * same window; a date_created lower bound therefore captures all of them. This
	 * keeps the scan constant-time regardless of total order count (15k+ and
	 * growing), with no flat date column, no backfill migration, and no object
	 * cache required.
	 *
	 * @return array<int,array<string,array<string,int>>>
	 */
	private static function bookings_map(): array {
		$version = \WC_Cache_Helper::get_transient_version( 'fn_slot_bookings' );
		$key     = 'fn_slot_bookings_' . $version;
		$cached  = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		// Only orders created within the booking window can still carry a future
		// fulfilment date; anything older is necessarily in the past. The margin
		// keeps us safe against cut-off offsets and timezone slop.
		$lookback = ( self::WINDOW_DAYS + 21 ) * DAY_IN_SECONDS;

		$map    = [];
		$orders = wc_get_orders(
			[
				'status'       => PrepOrderStatus::active_statuses(),
				'limit'        => -1,
				'meta_key'     => '_fn_fulfilment',
				'date_created' => '>=' . ( time() - $lookback ),
				'return'       => 'ids',
			]
		);
		foreach ( $orders as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			$ff = $o->get_meta( '_fn_fulfilment' );
			if ( ! is_array( $ff ) ) {
				continue;
			}
			$pid  = (int) ( $ff['profile_id'] ?? 0 );
			$date = (string) ( $ff['date'] ?? '' );
			$slot = is_array( $ff['slot'] ?? null ) ? $ff['slot'] : [];
			$skey = (string) ( $slot['start'] ?? '' ) . '|' . (string) ( $slot['end'] ?? '' );
			if ( 0 === $pid || '' === $date || '|' === $skey || $date < $today ) {
				continue;
			}
			$map[ $pid ][ $date ][ $skey ] = ( $map[ $pid ][ $date ][ $skey ] ?? 0 ) + 1;
		}

		// Correctness comes from the version bump on order changes; the TTL is a
		// safety backstop in case a bump is ever missed.
		set_transient( $key, $map, HOUR_IN_SECONDS );
		return $map;
	}

	/** Invalidate the cached booking counts (see bookings_map()). */
	public static function flush_bookings_cache(): void {
		\WC_Cache_Helper::get_transient_version( 'fn_slot_bookings', true );
	}
}

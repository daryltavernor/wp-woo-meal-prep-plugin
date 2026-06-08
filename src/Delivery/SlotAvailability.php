<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use DateTimeImmutable;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;

final class SlotAvailability {

	public const WINDOW_DAYS = 14;

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
	 * Booked-slot counts for every active order, as a nested map:
	 *   [ profile_id ][ 'Y-m-d' ][ 'start|end' ] => count.
	 *
	 * Built in ONE pass and cached until an order is created or changes status
	 * (see flush_bookings_cache()). This replaces the previous design, where the
	 * count was recomputed for every (day × profile × slot) combination and each
	 * recomputation loaded every fulfilment order — so a single /slots request
	 * scanned the whole order table dozens of times. Now it scans once, then
	 * serves from cache until the underlying bookings actually change.
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

		// Past bookings never affect remaining capacity (options() only looks up
		// dates from tomorrow onward), so drop them. This keeps the cached map
		// small — important with transients stored in the DB (no object cache).
		$today  = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$map    = [];
		$orders = wc_get_orders(
			[
				'status'   => PrepOrderStatus::active_statuses(),
				'limit'    => -1,
				'meta_key' => '_fn_fulfilment',
				'return'   => 'ids',
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

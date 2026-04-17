<?php
/**
 * Computes available fulfilment slots for a given postcode over a rolling window.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use DateTimeImmutable;
use DateTimeZone;

final class SlotAvailability {

	public function __construct(
		private readonly ProfileResolver $resolver,
		private readonly BlockedDates $blocked
	) {}

	public function register(): void {
		// Pure service.
	}

	/**
	 * Returns available { method, profile_id, profile_name, date, slot } for a given postcode.
	 * Filters out blocked dates and past/now-too-soon slots (min lead hours) and slots that are at capacity.
	 *
	 * @return array<int,array{method:string,profile_id:int,profile_name:string,date:string,slot:array{start:string,end:string,capacity:?int,remaining:?int}}>
	 */
	public function for_postcode( string $postcode, int $window_days = 14, int $min_lead_hours = 24 ): array {
		$profiles = $this->resolver->resolve( $postcode );
		if ( empty( $profiles ) ) {
			return [];
		}

		$tz    = wp_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$earliest = $now->modify( sprintf( '+%d hours', $min_lead_hours ) );

		$out = [];
		for ( $i = 0; $i < $window_days; $i++ ) {
			$day    = $now->modify( sprintf( '+%d days', $i ) );
			$date_s = $day->format( 'Y-m-d' );
			if ( $this->blocked::is_blocked( $date_s ) ) {
				continue;
			}
			$day_mask = Profile::php_day_to_mask( (int) $day->format( 'N' ) );

			foreach ( $profiles as $profile ) {
				if ( ! ( $profile['days_mask'] & $day_mask ) ) {
					continue;
				}
				foreach ( $profile['slots'] as $slot ) {
					$slot_start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date_s . ' ' . $slot['start'], $tz );
					if ( ! $slot_start || $slot_start < $earliest ) {
						continue;
					}
					$remaining = $slot['capacity'] !== null
						? max( 0, $slot['capacity'] - $this->booked( $profile['id'], $date_s, $slot['start'], $slot['end'] ) )
						: null;
					if ( null !== $remaining && $remaining <= 0 ) {
						continue;
					}
					$out[] = [
						'method'       => $profile['method'],
						'profile_id'   => $profile['id'],
						'profile_name' => $profile['name'],
						'date'         => $date_s,
						'slot'         => [
							'start'     => $slot['start'],
							'end'       => $slot['end'],
							'capacity'  => $slot['capacity'],
							'remaining' => $remaining,
						],
					];
				}
			}
		}
		return $out;
	}

	/**
	 * Count orders already booked against a profile/date/slot so capacity can be enforced.
	 * Uses a single wc_get_orders lookup with a meta_query.
	 */
	private function booked( int $profile_id, string $date, string $start, string $end ): int {
		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'status'     => [ 'processing', 'on-hold', 'completed', 'pending' ],
				'return'     => 'ids',
				'meta_query' => [
					[ 'key' => '_fn_fulfilment', 'compare' => 'EXISTS' ],
				],
			]
		);
		$count = 0;
		foreach ( $orders as $id ) {
			$f = get_post_meta( $id, '_fn_fulfilment', true );
			if ( ! is_array( $f ) ) {
				$order = wc_get_order( $id );
				$f     = $order ? $order->get_meta( '_fn_fulfilment' ) : [];
			}
			if ( empty( $f ) ) {
				continue;
			}
			if ( (int) ( $f['profile_id'] ?? 0 ) !== $profile_id ) {
				continue;
			}
			if ( ( $f['date'] ?? '' ) !== $date ) {
				continue;
			}
			if ( ( $f['slot']['start'] ?? '' ) !== $start || ( $f['slot']['end'] ?? '' ) !== $end ) {
				continue;
			}
			$count++;
		}
		return $count;
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Stats;

use FastNutrition\MealPrep\Admin\PrepSheet;
use FastNutrition\MealPrep\Cart\Selection;

/**
 * Daily fulfilment rollup ledger.
 *
 * Aggregates each fulfilment date's paid orders into three permanent tables so
 * the Reports page can compare any period (day, month, year, custom range)
 * without re-scanning order history:
 *
 *   - fn_daily_stats            : (date, method) -> orders, meals, sweets,
 *                                 add-ons, items, revenue, meal-mode mix.
 *   - fn_daily_ingredient_stats : (date, ingredient_id) -> portions.
 *   - fn_daily_meal_stats       : (date, meal_key) -> qty (build combo signature
 *                                 or "p:{product_id}" for set/standalone meals).
 *
 * Rows are written once and kept indefinitely. There is NO write-time tracking
 * on checkout: every order already stores its composition in `_fn_selection` and
 * fulfilment in `_fn_fulfilment`, so the rollup runs entirely in background
 * Action Scheduler jobs. `rollup_date()` is the single primitive — it recomputes
 * a date wholesale (DELETE + INSERT), so it is idempotent and self-correcting
 * when orders move date, get cancelled, or are edited.
 */
final class StatsRollup {

	public const GROUP         = 'fastnutrition';
	public const HOOK_DAILY    = 'fn_stats_rollup_daily';
	public const HOOK_DATE     = 'fn_stats_rollup_date';  // arg: 'Y-m-d'
	public const HOOK_BACKFILL = 'fn_stats_backfill';     // arg: int day-offset

	/** Sale statuses — matches PopularCombos (excludes prep/label-only, refunded, cancelled, on-hold, failed). */
	private const COUNTED_STATUSES = [ 'processing', 'completed' ];

	/** The nightly job re-rolls recent past + near future, where orders are still changing. */
	private const REROLL_PAST_DAYS = 45;
	private const FUTURE_DAYS       = 90;

	/** Dates rolled up per backfill batch (chunked so it never times out). */
	private const BACKFILL_CHUNK = 30;

	public function register(): void {
		add_action( self::HOOK_DAILY, [ __CLASS__, 'run_daily' ] );
		add_action( self::HOOK_DATE, [ __CLASS__, 'rollup_date' ], 10, 1 );
		add_action( self::HOOK_BACKFILL, [ __CLASS__, 'run_backfill_batch' ], 10, 1 );
		// Re-roll a date whenever an order on it changes status (covers edits to
		// old orders beyond the nightly window). Async, so no checkout overhead.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 20, 4 );
	}

	/** Ensure the nightly rollup is scheduled (called from activation / upgrade, not per request). */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK_DAILY, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_DAILY, [], self::GROUP );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_DAILY, [], self::GROUP );
		}
	}

	/** Seed the full history once (idempotent; guarded so an upgrade re-run is a no-op). */
	public static function ensure_backfilled(): void {
		if ( get_option( 'fn_stats_backfilled' ) ) {
			return;
		}
		update_option( 'fn_stats_backfilled', 1, false );
		self::queue_backfill();
	}

	// ---------------------------------------------------------------- tables

	public static function stats_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_daily_stats';
	}

	public static function ingredient_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_daily_ingredient_stats';
	}

	public static function meal_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_daily_meal_stats';
	}

	// ------------------------------------------------------------- nightly job

	public static function run_daily(): void {
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'now', $tz );
		for ( $i = -self::REROLL_PAST_DAYS; $i <= self::FUTURE_DAYS; $i++ ) {
			self::rollup_date( $today->modify( ( $i >= 0 ? '+' : '' ) . $i . ' days' )->format( 'Y-m-d' ) );
		}
	}

	// --------------------------------------------------- per-date rollup primitive

	/**
	 * Recompute one fulfilment date wholesale from its current paid orders.
	 * Idempotent: safe to call any number of times, from any source.
	 *
	 * @param string $date 'Y-m-d'
	 */
	public static function rollup_date( $date ): void {
		$date = (string) $date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$matched = PrepSheet::collect_matched_by_date( $date, '', self::COUNTED_STATUSES );

		$by_method   = []; // method => aggregate row
		$ingredients = []; // ingredient_id => portions
		$meals       = []; // meal_key => [ qty, mode ]

		foreach ( $matched as $m ) {
			$order  = $m['order'];
			$method = (string) ( $m['fulfilment']['type'] ?? '' );
			if ( '' === $method ) {
				continue; // no fulfilment method — not a delivery or a collection.
			}
			if ( ! isset( $by_method[ $method ] ) ) {
				$by_method[ $method ] = [
					'orders'     => 0,
					'meals'      => 0,
					'sweets'     => 0,
					'addons'     => 0,
					'revenue'    => 0.0,
					'build'      => 0,
					'set'        => 0,
					'standalone' => 0,
				];
			}
			$by_method[ $method ]['orders']  += 1;
			$by_method[ $method ]['revenue'] += (float) $order->get_total(); // gross order total.

			foreach ( $order->get_items() as $item ) {
				$qty = (int) $item->get_quantity();
				$sel = $item->get_meta( '_fn_selection', true );
				if ( ! is_array( $sel ) ) {
					$by_method[ $method ]['meals'] += $qty;
					continue;
				}
				$mode = (string) ( $sel['mode'] ?? '' );

				if ( Selection::is_sweet( $sel ) ) {
					$by_method[ $method ]['sweets'] += $qty;
				} else {
					$by_method[ $method ]['meals'] += $qty;
					if ( 'build' === $mode ) {
						$by_method[ $method ]['build'] += $qty;
					} elseif ( 'set' === $mode ) {
						$by_method[ $method ]['set'] += $qty;
					} else {
						$by_method[ $method ]['standalone'] += $qty;
					}
					$sig = ( 'build' === $mode ) ? Selection::combo_signature( $sel ) : null;
					$key = $sig ?? ( 'p:' . (int) $item->get_product_id() );
					if ( ! isset( $meals[ $key ] ) ) {
						$meals[ $key ] = [ 'qty' => 0, 'mode' => $mode ];
					}
					$meals[ $key ]['qty'] += $qty;
				}

				foreach ( Selection::addon_counts( $sel ) as $n ) {
					$by_method[ $method ]['addons'] += $n * $qty;
				}
				foreach ( Selection::ingredient_ids( $sel ) as $ing ) {
					$ingredients[ (int) $ing ] = ( $ingredients[ (int) $ing ] ?? 0 ) + $qty;
				}
			}
		}

		self::write_date( $date, $by_method, $ingredients, $meals );
	}

	private static function write_date( string $date, array $by_method, array $ingredients, array $meals ): void {
		global $wpdb;
		$stats = self::stats_table();
		$ing   = self::ingredient_table();
		$mealt = self::meal_table();
		$now   = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Wholesale replace for this date so cancelled / moved orders drop out cleanly.
		$wpdb->delete( $stats, [ 'stat_date' => $date ], [ '%s' ] );
		$wpdb->delete( $ing, [ 'stat_date' => $date ], [ '%s' ] );
		$wpdb->delete( $mealt, [ 'stat_date' => $date ], [ '%s' ] );

		foreach ( $by_method as $method => $a ) {
			$items = (int) $a['meals'] + (int) $a['sweets'] + (int) $a['addons'];
			$wpdb->insert(
				$stats,
				[
					'stat_date'        => $date,
					'method'           => (string) $method,
					'orders'           => (int) $a['orders'],
					'meals'            => (int) $a['meals'],
					'sweets'           => (int) $a['sweets'],
					'addons'           => (int) $a['addons'],
					'items_total'      => $items,
					'revenue'          => round( (float) $a['revenue'], 2 ),
					'meals_build'      => (int) $a['build'],
					'meals_set'        => (int) $a['set'],
					'meals_standalone' => (int) $a['standalone'],
					'updated_at'       => $now,
				]
			);
		}
		foreach ( $ingredients as $id => $portions ) {
			if ( (int) $id <= 0 ) {
				continue;
			}
			$wpdb->insert(
				$ing,
				[
					'stat_date'     => $date,
					'ingredient_id' => (int) $id,
					'portions'      => (int) $portions,
					'updated_at'    => $now,
				]
			);
		}
		foreach ( $meals as $key => $d ) {
			$wpdb->insert(
				$mealt,
				[
					'stat_date'  => $date,
					'meal_key'   => (string) $key,
					'mode'       => (string) $d['mode'],
					'qty'        => (int) $d['qty'],
					'updated_at' => $now,
				]
			);
		}
		// phpcs:enable
	}

	// -------------------------------------------------------- status-change hook

	/**
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 * @param mixed  $order
	 */
	public static function on_order_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$ff   = $order->get_meta( '_fn_fulfilment' );
		$date = is_array( $ff ) ? (string) ( $ff['date'] ?? '' ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::HOOK_DATE, [ $date ], self::GROUP ) ) {
				return; // already queued for this date.
			}
			as_enqueue_async_action( self::HOOK_DATE, [ $date ], self::GROUP );
		} else {
			self::rollup_date( $date );
		}
	}

	// ----------------------------------------------------------------- backfill

	/** Queue a one-off, chunked backfill across all historical dates. */
	public static function queue_backfill(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$ids = wc_get_orders(
			[
				'status'  => self::COUNTED_STATUSES,
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
			]
		);
		if ( empty( $ids ) ) {
			return;
		}
		$first   = wc_get_order( (int) $ids[0] );
		$created = $first ? $first->get_date_created() : null;
		if ( ! $created ) {
			return;
		}
		update_option( 'fn_stats_backfill_start', $created->format( 'Y-m-d' ), false );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BACKFILL, [ 0 ], self::GROUP );
		} else {
			self::run_backfill_batch( 0 );
		}
	}

	/**
	 * Roll up one chunk of historical dates, then enqueue the next chunk until
	 * the range [first order .. today + FUTURE_DAYS] is covered.
	 *
	 * @param int $offset Day-offset from the stored start date.
	 */
	public static function run_backfill_batch( $offset ): void {
		$offset = max( 0, (int) $offset );
		$start  = (string) get_option( 'fn_stats_backfill_start', '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			return;
		}
		$tz     = wp_timezone();
		$begin  = new \DateTimeImmutable( $start, $tz );
		$end    = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+' . self::FUTURE_DAYS . ' days' );
		$cursor = $begin->modify( "+{$offset} days" );

		$done = 0;
		while ( $cursor <= $end && $done < self::BACKFILL_CHUNK ) {
			self::rollup_date( $cursor->format( 'Y-m-d' ) );
			$cursor = $cursor->modify( '+1 day' );
			++$done;
		}

		if ( $cursor <= $end ) {
			$next = $offset + self::BACKFILL_CHUNK;
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_BACKFILL, [ $next ], self::GROUP );
			} else {
				self::run_backfill_batch( $next );
			}
		}
	}
}

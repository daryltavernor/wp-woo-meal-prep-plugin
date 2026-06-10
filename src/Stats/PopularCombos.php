<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Stats;

use FastNutrition\MealPrep\Cart\Selection;

/**
 * Computes the most popular meal combinations (and ingredients) from order
 * history on a weekly schedule, and stores a small precomputed result that the
 * "Popular Combinations" product mode reads.
 *
 * There is NO write-time tracking: every order line already stores its full
 * composition in `_fn_selection`, so popularity is derived by aggregating recent
 * orders in a background Action Scheduler job — zero checkout overhead. The scan:
 *   - takes paid orders (processing + completed; in-store included, prep/label
 *     and refunded/cancelled excluded) from the last WINDOW_DAYS;
 *   - reads their selections in bulk straight from woocommerce_order_itemmeta
 *     (order IDs come from wc_get_orders, so it's HPOS-safe — the order-item
 *     tables are unchanged by HPOS) rather than hydrating WC_Order objects;
 *   - tallies build combinations (add-ons stripped, greens order-normalised) and
 *     ingredient usage, weighted by quantity sold.
 *
 * The result is a single small option (top 20 combos + top ingredients), read on
 * the product page. Scheduling is registered from the Activator, not on every
 * request, so this class adds nothing to normal page loads.
 */
final class PopularCombos {

	public const OPTION      = 'fn_popular_combos';
	public const HOOK        = 'fn_popular_combos_recompute';
	public const GROUP       = 'fastnutrition';
	public const WINDOW_DAYS = 90;

	private const STORE_TOP         = 20;
	private const STORE_INGREDIENTS = 30;

	/** Statuses that count as a sale — excludes prep/label-only, refunded, cancelled, on-hold, failed. */
	private const COUNTED_STATUSES = [ 'processing', 'completed' ];

	public function register(): void {
		// Only the background worker callback — no per-request hooks.
		add_action( self::HOOK, [ __CLASS__, 'recompute' ] );
	}

	/** Ensure the weekly recompute is scheduled (called from activation / upgrade, not per request). */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, WEEK_IN_SECONDS, self::HOOK, [], self::GROUP );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
	}

	/** Queue a one-off background recompute (admin "Recompute now" button + first seed). */
	public static function queue_recompute(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [], self::GROUP );
		} else {
			self::recompute();
		}
	}

	/** @return array{generated:int,window_days:int,combos:array,ingredients:array} */
	public static function get_results(): array {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) ) {
			return [ 'generated' => 0, 'window_days' => self::WINDOW_DAYS, 'combos' => [], 'ingredients' => [] ];
		}
		$data['combos']      = is_array( $data['combos'] ?? null ) ? $data['combos'] : [];
		$data['ingredients'] = is_array( $data['ingredients'] ?? null ) ? $data['ingredients'] : [];
		return $data;
	}

	/** Ranked combo compositions (up to STORE_TOP) for the front end to filter + display. */
	public static function ranked_combos(): array {
		return self::get_results()['combos'];
	}

	/**
	 * Rebuild the popularity stats from the last WINDOW_DAYS of paid orders.
	 * Runs only in the background (Action Scheduler) — never on a customer request.
	 */
	public static function recompute(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$order_ids = wc_get_orders(
			[
				'status'       => self::COUNTED_STATUSES,
				'date_created' => '>=' . ( time() - self::WINDOW_DAYS * DAY_IN_SECONDS ),
				'limit'        => -1,
				'return'       => 'ids',
			]
		);

		$combo_counts      = [];
		$ingredient_counts = [];

		if ( ! empty( $order_ids ) ) {
			global $wpdb;
			$items_table = $wpdb->prefix . 'woocommerce_order_items';
			$meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

			foreach ( array_chunk( array_map( 'intval', $order_ids ), 1000 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// Bulk-read selections + quantities for these orders' line items in one query.
				// Direct query on the order-item tables (HPOS-stable); IDs already scoped by wc_get_orders().
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT sel.meta_value AS selection, COALESCE( qty.meta_value, '1' ) AS qty
						 FROM {$items_table} oi
						 INNER JOIN {$meta_table} sel ON sel.order_item_id = oi.order_item_id AND sel.meta_key = '_fn_selection'
						 LEFT JOIN {$meta_table} qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
						 WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$placeholders})",
						$chunk
					)
				);
				// phpcs:enable
				foreach ( (array) $rows as $row ) {
					$sel = maybe_unserialize( $row->selection );
					if ( ! is_array( $sel ) ) {
						continue;
					}
					$qty = max( 1, (int) $row->qty );

					$sig = Selection::combo_signature( $sel );
					if ( null !== $sig ) {
						if ( ! isset( $combo_counts[ $sig ] ) ) {
							$combo_counts[ $sig ] = [ 'count' => 0, 'composition' => Selection::combo_composition( $sel ) ];
						}
						$combo_counts[ $sig ]['count'] += $qty;
					}
					foreach ( Selection::ingredient_ids( $sel ) as $ing_id ) {
						$ingredient_counts[ (int) $ing_id ] = ( $ingredient_counts[ (int) $ing_id ] ?? 0 ) + $qty;
					}
				}
			}
		}

		uasort( $combo_counts, static fn( $a, $b ) => $b['count'] <=> $a['count'] );
		$combos = [];
		foreach ( array_slice( $combo_counts, 0, self::STORE_TOP, true ) as $data ) {
			$combos[] = [
				'protein_id' => $data['composition']['protein_id'],
				'carb_id'    => $data['composition']['carb_id'],
				'greens_ids' => $data['composition']['greens_ids'],
				'count'      => (int) $data['count'],
			];
		}

		arsort( $ingredient_counts );
		$ingredients = [];
		foreach ( array_slice( $ingredient_counts, 0, self::STORE_INGREDIENTS, true ) as $id => $count ) {
			$ingredients[] = [ 'id' => (int) $id, 'count' => (int) $count ];
		}

		update_option(
			self::OPTION,
			[
				'generated'   => time(),
				'window_days' => self::WINDOW_DAYS,
				'combos'      => $combos,
				'ingredients' => $ingredients,
			],
			false
		);
	}
}

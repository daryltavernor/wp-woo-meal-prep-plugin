<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\Selection;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;

/**
 * "Next 7 days" fulfilment summary shown above the WooCommerce Orders table.
 *
 * A quick planning glance: per upcoming day, the orders and meals to make plus
 * the delivery / collection split. It uses the same status set as the prep sheet
 * (PrepOrderStatus::active_statuses) so the figures match what the kitchen will
 * actually make, computed from a single bounded order scan and cached for a few
 * minutes (busted whenever an order is created or changes status) so it never
 * slows the orders screen.
 */
final class UpcomingFulfilment {

	private const DAYS      = 7;
	private const CACHE_KEY = 'fn_upcoming_fulfilment_v3';

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'maybe_render' ] );
		add_action( 'woocommerce_new_order', [ __CLASS__, 'flush' ] );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'flush' ] );
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	public function maybe_render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		$id     = $screen ? $screen->id : '';
		// HPOS orders screen and the legacy shop_order list.
		if ( ! in_array( $id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		$this->render( self::data() );
	}

	/**
	 * Per-day rows for the next 7 days.
	 *
	 * @return array<int,array{date:string,label:string,orders:int,meals:int,addons:int,delivery:int,collection:int}>
	 */
	public static function data(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'now', $tz );
		$rows  = [];
		for ( $i = 0; $i < self::DAYS; $i++ ) {
			$d                              = $today->modify( "+{$i} days" );
			$rows[ $d->format( 'Y-m-d' ) ] = [
				'date'       => $d->format( 'Y-m-d' ),
				'label'      => $d->format( 'D j M' ),
				'orders'     => 0,
				'meals'      => 0,
				'addons'     => 0,
				'delivery'   => 0,
				'collection' => 0,
				'plans'      => [], // variation id => count (meal plans, kept separate).
			];
		}

		$plan_config = MealPlansSettings::get_config(); // product id => excluded variation ids.

		if ( function_exists( 'wc_get_orders' ) ) {
			// One bounded scan covers every order that could fall on these dates:
			// the loosest lower bound is the earliest creation for the first day.
			$first  = (string) array_key_first( $rows );
			$orders = wc_get_orders(
				[
					'status'       => PrepOrderStatus::active_statuses(),
					'limit'        => -1,
					'meta_key'     => '_fn_fulfilment',
					'date_created' => '>=' . SlotAvailability::created_since_for_date( $first ),
				]
			);
			foreach ( $orders as $order ) {
				$ff = $order->get_meta( '_fn_fulfilment' );
				if ( ! is_array( $ff ) ) {
					continue;
				}
				$d = (string) ( $ff['date'] ?? '' );
				if ( ! isset( $rows[ $d ] ) ) {
					continue;
				}
				$method                = (string) ( $ff['type'] ?? '' );
				$rows[ $d ]['orders'] += 1;
				if ( 'delivery' === $method ) {
					$rows[ $d ]['delivery'] += 1;
				} elseif ( 'collection' === $method ) {
					$rows[ $d ]['collection'] += 1;
				}
				// Every line item is a plated meal/sweet (quantities sum to meals);
				// add-ons live inside each selection and are tallied separately.
				// Meal-plan variations are counted on their own and NOT as meals.
				foreach ( $order->get_items() as $item ) {
					$qty = (int) $item->get_quantity();

					$pid = (int) $item->get_product_id();
					$vid = (int) $item->get_variation_id();
					if ( $vid > 0 && isset( $plan_config[ $pid ] ) && ! in_array( $vid, $plan_config[ $pid ], true ) ) {
						$rows[ $d ]['plans'][ $vid ] = ( $rows[ $d ]['plans'][ $vid ] ?? 0 ) + $qty;
						continue;
					}

					$rows[ $d ]['meals'] += $qty;
					$sel                  = $item->get_meta( '_fn_selection', true );
					if ( is_array( $sel ) ) {
						foreach ( Selection::addon_counts( $sel ) as $n ) {
							$rows[ $d ]['addons'] += $n * $qty;
						}
					}
				}
			}
		}

		$rows = array_values( $rows );
		set_transient( self::CACHE_KEY, $rows, 5 * MINUTE_IN_SECONDS );
		return $rows;
	}

	private function render( array $rows ): void {
		$show_plans  = ! empty( MealPlansSettings::get_config() );
		$label_cache = [];
		$plans_cell  = static function ( array $plans ) use ( &$label_cache ): string {
			if ( empty( $plans ) ) {
				return '—';
			}
			ksort( $plans );
			$parts = [];
			foreach ( $plans as $vid => $count ) {
				if ( ! isset( $label_cache[ $vid ] ) ) {
					$label_cache[ $vid ] = MealPlansSettings::variation_label( (int) $vid );
				}
				$parts[] = esc_html( $label_cache[ $vid ] . ' ×' . (int) $count );
			}
			return implode( '<br>', $parts );
		};

		$t_orders = 0;
		$t_meals  = 0;
		$t_addons = 0;
		$t_del    = 0;
		$t_col    = 0;
		$t_plans  = [];
		foreach ( $rows as $r ) {
			$t_orders += (int) $r['orders'];
			$t_meals  += (int) $r['meals'];
			$t_addons += (int) ( $r['addons'] ?? 0 );
			$t_del    += (int) $r['delivery'];
			$t_col    += (int) $r['collection'];
			foreach ( (array) ( $r['plans'] ?? [] ) as $vid => $count ) {
				$t_plans[ $vid ] = ( $t_plans[ $vid ] ?? 0 ) + (int) $count;
			}
		}

		// Column label + hover/explanation help, so staff understand each figure.
		$cols = [
			'day'         => [ __( 'Day', 'fastnutrition-mealprep' ), __( 'Each of the next 7 days, by delivery/collection date.', 'fastnutrition-mealprep' ) ],
			'orders'      => [ __( 'Orders', 'fastnutrition-mealprep' ), __( 'Orders to fulfil that day (deliveries + collections).', 'fastnutrition-mealprep' ) ],
			'meals'       => [ __( 'Meals', 'fastnutrition-mealprep' ), __( 'Individual meals to make that day — built, set and standalone meals plus sweets — from online orders AND from the in-store Label Maker and Quick Order. Excludes add-ons and meal plans.', 'fastnutrition-mealprep' ) ],
			'addons'      => [ __( 'Add-ons', 'fastnutrition-mealprep' ), __( 'Extra add-on items to prepare that day.', 'fastnutrition-mealprep' ) ],
			'deliveries'  => [ __( 'Deliveries', 'fastnutrition-mealprep' ), __( 'Number of orders going out for delivery that day.', 'fastnutrition-mealprep' ) ],
			'collections' => [ __( 'Collections', 'fastnutrition-mealprep' ), __( 'Number of orders being collected that day.', 'fastnutrition-mealprep' ) ],
		];
		if ( $show_plans ) {
			$cols['plans'] = [ __( 'Meal plans', 'fastnutrition-mealprep' ), __( 'For information only: how many of each meal-plan product was sold for that day. Meal plans are NOT added to the Meals count until their labels are made on the Label Maker — at which point those meals appear in the Meals column like any other.', 'fastnutrition-mealprep' ) ];
		}

		echo '<div class="fn-upcoming notice notice-info" style="padding:10px 14px;">';
		echo '<h2 style="margin:.4em 0;">' . esc_html__( 'Upcoming fulfilment — next 7 days', 'fastnutrition-mealprep' ) . '</h2>';

		echo '<details style="margin:.2em 0 .6em;"><summary style="cursor:pointer;">' . esc_html__( 'What do these columns mean?', 'fastnutrition-mealprep' ) . '</summary>';
		echo '<ul style="margin:.4em 0 .2em 1.2em;list-style:disc;">';
		foreach ( $cols as $c ) {
			echo '<li><strong>' . esc_html( $c[0] ) . '</strong> — ' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul></details>';

		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th title="' . esc_attr( $c[1] ) . '">' . esc_html( $c[0] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['label'] ) . '</td>';
			echo '<td>' . (int) $r['orders'] . '</td>';
			echo '<td>' . (int) $r['meals'] . '</td>';
			echo '<td>' . (int) ( $r['addons'] ?? 0 ) . '</td>';
			echo '<td>' . (int) $r['delivery'] . '</td>';
			echo '<td>' . (int) $r['collection'] . '</td>';
			if ( $show_plans ) {
				echo '<td>' . $plans_cell( (array) ( $r['plans'] ?? [] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tr>';
		}
		echo '</tbody><tfoot><tr style="font-weight:600;">';
		echo '<td>' . esc_html__( 'Total', 'fastnutrition-mealprep' ) . '</td>';
		echo '<td>' . (int) $t_orders . '</td>';
		echo '<td>' . (int) $t_meals . '</td>';
		echo '<td>' . (int) $t_addons . '</td>';
		echo '<td>' . (int) $t_del . '</td>';
		echo '<td>' . (int) $t_col . '</td>';
		if ( $show_plans ) {
			echo '<td>' . $plans_cell( $t_plans ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tr></tfoot></table>';
		echo '</div>';
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Stats\StatsRollup;
use WP_Error;

/**
 * Amend an order's fulfilment date after it's been placed — from the order edit
 * screen (single) or the Orders list (many, via a "Change fulfilment date"
 * button + a dedicated confirmation page).
 *
 * Only the date changes (slot time, delivery/collection type and profile are
 * kept); everything that keys off the date is then re-synced for BOTH the old
 * and new day — the prep cache, the stats ledger, slot capacity and the 7-day
 * Orders widget — and an order note is added for audit. Labels read the date
 * live, so they print correctly next time.
 */
final class FulfilmentAmend {

	public const PAGE_SLUG = 'fn-amend-dates';

	public function register(): void {
		// Single order: editable date in the order-data panel + save handler.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'render_single_editor' ], 20, 1 );
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save_single' ], 50, 1 );

		// Orders list: a button (added via JS) that sends ticked orders to a
		// dedicated confirmation page — no bulk dropdown, no reliance on notices.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_button' ] );
		add_action( 'admin_menu', [ $this, 'register_page' ], 60 );
		add_action( 'admin_head', [ $this, 'hide_page' ] );
		add_action( 'admin_post_fn_amend_dates', [ __CLASS__, 'process' ] );
	}

	// ---------------------------------------------------------------- core

	/**
	 * Change one order's fulfilment date and (optionally) re-sync downstream data.
	 *
	 * @return true|WP_Error
	 */
	public static function amend_date( int $order_id, string $new_date, bool $resync = true ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
			return new WP_Error( 'fn_bad_date', __( 'Please choose a valid date.', 'fastnutrition-mealprep' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'fn_no_order', __( 'Order not found.', 'fastnutrition-mealprep' ) );
		}
		$ff = $order->get_meta( '_fn_fulfilment' );
		if ( ! is_array( $ff ) || empty( $ff['date'] ) ) {
			return new WP_Error( 'fn_no_fulfilment', __( 'This order has no fulfilment date to change.', 'fastnutrition-mealprep' ) );
		}
		$old_date = (string) $ff['date'];
		if ( $old_date === $new_date ) {
			return true; // nothing to do.
		}

		$ff['date'] = $new_date;
		$order->update_meta_data( '_fn_fulfilment', $ff );
		$order->add_order_note(
			sprintf(
				/* translators: 1: old date, 2: new date, 3: user name */
				__( 'Fulfilment date changed from %1$s to %2$s by %3$s.', 'fastnutrition-mealprep' ),
				$old_date,
				$new_date,
				self::current_user_label()
			)
		);
		$order->save();

		if ( $resync ) {
			self::resync_dates( [ $old_date, $new_date ] );
		}
		return true;
	}

	/** Rebuild every system that keys off a fulfilment date, for the given dates. */
	public static function resync_dates( array $dates ): void {
		foreach ( array_unique( array_filter( array_map( 'strval', $dates ) ) ) as $d ) {
			OrderItemMeta::rebuild_for_date( $d ); // prep sheet / dashboard.
			StatsRollup::rollup_date( $d );        // reports / stats ledger.
		}
		SlotAvailability::flush_bookings_cache();   // slot capacity.
		UpcomingFulfilment::flush();                // 7-day Orders widget.
	}

	private static function current_user_label(): string {
		$user = wp_get_current_user();
		return $user && $user->display_name ? $user->display_name : __( 'an administrator', 'fastnutrition-mealprep' );
	}

	private static function orders_url( string $return = '' ): string {
		$default = admin_url( 'admin.php?page=wc-orders' );
		return '' !== $return ? wp_validate_redirect( $return, $default ) : $default;
	}

	// ------------------------------------------------------------- single order

	public function render_single_editor( $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$ff = $order->get_meta( '_fn_fulfilment' );
		if ( ! is_array( $ff ) || empty( $ff['date'] ) ) {
			return;
		}
		wp_nonce_field( 'fn_amend_single', 'fn_amend_nonce' );
		echo '<p class="form-field form-field-wide"><label for="fn_amend_date"><strong>' . esc_html__( 'Amend fulfilment date', 'fastnutrition-mealprep' ) . '</strong></label>';
		echo '<input type="date" id="fn_amend_date" name="fn_amend_date" value="' . esc_attr( (string) $ff['date'] ) . '" />';
		echo '<span class="description" style="display:block;color:#646970;">' . esc_html__( 'Changing this updates the prep sheet, reports, slot capacity and the labels for the new date.', 'fastnutrition-mealprep' ) . '</span></p>';
	}

	public static function save_single( $order_id ): void {
		if ( ! isset( $_POST['fn_amend_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_amend_nonce'] ) ), 'fn_amend_single' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $_POST['fn_amend_date'] ) ) {
			return;
		}
		$new_date = sanitize_text_field( wp_unslash( (string) $_POST['fn_amend_date'] ) );
		self::amend_date( (int) $order_id, $new_date );
	}

	// ----------------------------------------------------------- orders button

	public function enqueue_button(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		wp_enqueue_script( 'fn-orders-amend', FN_MEALPREP_URL . 'assets/admin/orders-amend.js', [], FN_MEALPREP_VERSION, true );
		wp_localize_script(
			'fn-orders-amend',
			'fnOrdersAmend',
			[
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'label' => __( 'Change fulfilment date', 'fastnutrition-mealprep' ),
				'none'  => __( 'Please tick one or more orders first.', 'fastnutrition-mealprep' ),
			]
		);
	}

	public function register_page(): void {
		add_submenu_page(
			MenuRegistry::SLUG,
			__( 'Change fulfilment date', 'fastnutrition-mealprep' ),
			__( 'Change fulfilment date', 'fastnutrition-mealprep' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/** Keep the confirmation page reachable by URL but hidden from the menu. */
	public function hide_page(): void {
		remove_submenu_page( MenuRegistry::SLUG, self::PAGE_SLUG );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$return = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( (string) $_GET['return'] ) ) : '';
		$ids    = isset( $_GET['ids'] ) ? self::parse_ids( (string) wp_unslash( $_GET['ids'] ) ) : [];
		$done   = isset( $_GET['done'] );
		$count  = $done ? (int) $_GET['done'] : 0;
		// phpcs:enable

		echo '<div class="wrap"><h1>' . esc_html__( 'Change fulfilment date', 'fastnutrition-mealprep' ) . '</h1>';

		if ( $done ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d order updated.', '%d orders updated.', $count, 'fastnutrition-mealprep' ), $count ) ) . '</p></div>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::orders_url( $return ) ) . '">' . esc_html__( 'Back to orders', 'fastnutrition-mealprep' ) . '</a></p></div>';
			return;
		}

		if ( empty( $ids ) ) {
			echo '<p>' . esc_html__( 'No orders selected. Go to Orders, tick the orders to change, then click "Change fulfilment date".', 'fastnutrition-mealprep' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::orders_url( $return ) ) . '">' . esc_html__( 'Back to orders', 'fastnutrition-mealprep' ) . '</a></p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fn_amend_dates" />';
		echo '<input type="hidden" name="ids" value="' . esc_attr( implode( ',', $ids ) ) . '" />';
		echo '<input type="hidden" name="return" value="' . esc_attr( $return ) . '" />';
		wp_nonce_field( 'fn_amend_dates' );

		echo '<p><label><strong>' . esc_html__( 'New date:', 'fastnutrition-mealprep' ) . '</strong> <input type="date" name="new_date" required /></label></p>';

		echo '<table class="widefat striped" style="max-width:680px;margin-bottom:10px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Current date', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) {
				continue;
			}
			$ff = $order->get_meta( '_fn_fulfilment' );
			echo '<tr>';
			echo '<td>#' . (int) $order->get_id() . '</td>';
			echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( is_array( $ff ) ? (string) ( $ff['date'] ?? '—' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( is_array( $ff ) ? (string) ( $ff['type'] ?? '' ) : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Change date for these orders', 'fastnutrition-mealprep' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( self::orders_url( $return ) ) . '">' . esc_html__( 'Cancel', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form></div>';
	}

	public static function process(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fastnutrition-mealprep' ) );
		}
		check_admin_referer( 'fn_amend_dates' );

		$ids    = isset( $_POST['ids'] ) ? self::parse_ids( (string) wp_unslash( $_POST['ids'] ) ) : [];
		$new    = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_date'] ) ) : '';
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( (string) $_POST['return'] ) ) : '';
		$page   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( empty( $ids ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new ) ) {
			wp_safe_redirect( add_query_arg( [ 'ids' => implode( ',', $ids ), 'return' => rawurlencode( $return ) ], $page ) );
			exit;
		}

		// Collect the affected dates up front, amend without per-order re-sync,
		// then re-sync each unique date once.
		$dates = [ $new ];
		$count = 0;
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order ) {
				$ff = $order->get_meta( '_fn_fulfilment' );
				if ( is_array( $ff ) && ! empty( $ff['date'] ) ) {
					$dates[] = (string) $ff['date'];
				}
			}
			if ( true === self::amend_date( $oid, $new, false ) ) {
				++$count;
			}
		}
		self::resync_dates( $dates );

		wp_safe_redirect( add_query_arg( [ 'done' => $count, 'return' => rawurlencode( $return ) ], $page ) );
		exit;
	}

	/** @return int[] */
	private static function parse_ids( string $csv ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', explode( ',', $csv ) ) ) ) );
	}
}

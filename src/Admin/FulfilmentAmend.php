<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Stats\StatsRollup;
use WP_Error;

/**
 * Amend an order's fulfilment date after it's been placed — from the order edit
 * screen (single) or the Orders list (bulk, via a confirmation screen).
 *
 * The fulfilment date is one order-level value (`_fn_fulfilment['date']`); this
 * changes only the date (slot time, delivery/collection type and profile are
 * kept) and then re-syncs everything that keys off the date — the prep cache
 * (old + new day), the stats ledger, slot capacity and the 7-day Orders widget —
 * and leaves an order note for audit. Labels read the date live, so they print
 * correctly next time.
 */
final class FulfilmentAmend {

	private const BULK_ACTION = 'fn_change_fulfilment_date';

	public function register(): void {
		// Single order: editable date in the order-data panel + save handler.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'render_single_editor' ], 20, 1 );
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save_single' ], 50, 1 );

		// Bulk: action on both the HPOS and legacy orders screens.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_bulk_action' ] );
		add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk' ], 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle_bulk' ], 10, 3 );

		// Confirmation screen (rendered above the orders table) + its processor.
		add_action( 'admin_notices', [ $this, 'render_bulk_confirm' ] );
		add_action( 'admin_post_fn_amend_dates', [ __CLASS__, 'process_bulk' ] );
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

	// ------------------------------------------------------------- single order

	public function render_single_editor( $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) || ! current_user_can( 'edit_shop_orders' ) ) {
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
		if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['fn_amend_date'] ) ) {
			return;
		}
		$new_date = sanitize_text_field( wp_unslash( (string) $_POST['fn_amend_date'] ) );
		self::amend_date( (int) $order_id, $new_date );
	}

	// -------------------------------------------------------------------- bulk

	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Change fulfilment date', 'fastnutrition-mealprep' );
		return $actions;
	}

	/**
	 * Stash the selected ids and bounce to the confirmation screen (so we can ask
	 * for the new date). WordPress already nonce-checks the bulk request.
	 *
	 * @param string $redirect
	 * @param string $action
	 * @param int[]  $ids
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( self::BULK_ACTION !== $action || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return $redirect;
		}
		// Lowercase hex so it survives sanitize_key() unchanged on the way back.
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			'fn_amend_' . $token,
			[ 'ids' => $ids, 'return' => remove_query_arg( [ 'fn_amend_token', 'fn_amended' ], $redirect ) ],
			10 * MINUTE_IN_SECONDS
		);
		return add_query_arg( 'fn_amend_token', $token, remove_query_arg( [ 'fn_amend_token', 'fn_amended' ], $redirect ) );
	}

	public function render_bulk_confirm(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}

		// Success notice after processing.
		if ( isset( $_GET['fn_amended'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$n = (int) $_GET['fn_amended']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d order updated.', '%d orders updated.', $n, 'fastnutrition-mealprep' ), $n ) ) . '</p></div>';
			return;
		}

		$token = isset( $_GET['fn_amend_token'] ) ? sanitize_key( wp_unslash( $_GET['fn_amend_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return;
		}
		$data = get_transient( 'fn_amend_' . $token );
		if ( ! is_array( $data ) || empty( $data['ids'] ) ) {
			return;
		}

		echo '<div class="notice notice-info" style="padding:12px 16px;">';
		echo '<h2 style="margin:.2em 0;">' . esc_html__( 'Change fulfilment date', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fn_amend_dates" />';
		echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( 'fn_amend_dates' );

		echo '<p><label><strong>' . esc_html__( 'New date:', 'fastnutrition-mealprep' ) . '</strong> <input type="date" name="new_date" required /></label></p>';

		echo '<table class="widefat striped" style="max-width:680px;margin-bottom:10px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Current date', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( (array) $data['ids'] as $oid ) {
			$order = wc_get_order( (int) $oid );
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
		echo ' <a class="button" href="' . esc_url( (string) ( $data['return'] ?? admin_url() ) ) . '">' . esc_html__( 'Cancel', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form></div>';
	}

	public static function process_bulk(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fastnutrition-mealprep' ) );
		}
		check_admin_referer( 'fn_amend_dates' );

		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? get_transient( 'fn_amend_' . $token ) : false;
		$new   = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_date'] ) ) : '';
		$return = is_array( $data ) && ! empty( $data['return'] ) ? (string) $data['return'] : admin_url( 'admin.php?page=wc-orders' );

		if ( ! is_array( $data ) || empty( $data['ids'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new ) ) {
			wp_safe_redirect( $return );
			exit;
		}

		// Collect the affected dates up front, amend without per-order re-sync,
		// then re-sync each unique date once.
		$dates = [ $new ];
		$count = 0;
		foreach ( (array) $data['ids'] as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( $order ) {
				$ff = $order->get_meta( '_fn_fulfilment' );
				if ( is_array( $ff ) && ! empty( $ff['date'] ) ) {
					$dates[] = (string) $ff['date'];
				}
			}
			if ( true === self::amend_date( (int) $oid, $new, false ) ) {
				++$count;
			}
		}
		self::resync_dates( $dates );
		delete_transient( 'fn_amend_' . $token );

		wp_safe_redirect( add_query_arg( 'fn_amended', $count, $return ) );
		exit;
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Labels\LabelPrinter;

/**
 * Adds bulk actions to the WooCommerce Orders list (classic CPT screen
 * and HPOS screen) so the customer can tick orders and print labels for
 * just that selection — bypassing the date-window picker on
 * Meal Prep → Print Labels.
 *
 * Two actions are registered:
 *   - fn_print_labels_summary: one summary label per selected order
 *   - fn_print_labels_full:    summary + one label per meal item
 *
 * Bulk-action handlers run inside an already nonce-checked request
 * (WP_List_Table verifies `bulk-posts` / `bulk-orders` before dispatch),
 * so we only re-check the user capability before streaming the PDF.
 */
final class OrdersListBulkActions {

	private const ACTION_SUMMARY = 'fn_print_labels_summary';
	private const ACTION_FULL    = 'fn_print_labels_full';

	public function register(): void {
		// Classic, CPT-based orders screen.
		add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_actions' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle' ], 10, 3 );
		// HPOS (custom order tables) orders screen.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_actions' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_actions( array $actions ): array {
		$actions[ self::ACTION_FULL ]    = __( 'Print labels: summary + meal labels', 'fastnutrition-mealprep' );
		$actions[ self::ACTION_SUMMARY ] = __( 'Print labels: summary only', 'fastnutrition-mealprep' );
		return $actions;
	}

	/**
	 * @param string  $redirect_to URL WP will redirect to after the bulk action.
	 * @param string  $action      Selected bulk action key.
	 * @param int[]   $order_ids   IDs ticked by the user.
	 */
	public function handle( string $redirect_to, string $action, array $order_ids ): string {
		if ( self::ACTION_SUMMARY !== $action && self::ACTION_FULL !== $action ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}
		$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return add_query_arg( 'fn_labels_result', 'empty', $redirect_to );
		}
		$mode = ( self::ACTION_SUMMARY === $action ) ? LabelPrinter::MODE_SUMMARY : LabelPrinter::MODE_FULL;
		LabelPrinter::stream( $order_ids, $mode );
		// stream() exits.
		return $redirect_to;
	}
}

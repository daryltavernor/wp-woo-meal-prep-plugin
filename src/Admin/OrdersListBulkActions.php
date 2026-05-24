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
 * Real-print actions:
 *   - fn_print_labels_summary: one summary label per selected order
 *   - fn_print_labels_full:    summary + one label per meal item
 *
 * Test-print actions (each capped to ONE order and ONE meal label so a
 * 50-meal order doesn't print 50 test labels):
 *   - fn_print_labels_test_summary: one summary label, no-cache
 *   - fn_print_labels_test_meal:    one meal label, no-cache
 *   - fn_print_labels_test_both:    summary + one meal, no-cache
 *
 * Bulk-action handlers run inside an already nonce-checked request
 * (WP_List_Table verifies `bulk-posts` / `bulk-orders` before dispatch),
 * so we only re-check the user capability before streaming the PDF.
 */
final class OrdersListBulkActions {

	private const ACTION_SUMMARY      = 'fn_print_labels_summary';
	private const ACTION_FULL         = 'fn_print_labels_full';
	private const ACTION_TEST_SUMMARY = 'fn_print_labels_test_summary';
	private const ACTION_TEST_MEAL    = 'fn_print_labels_test_meal';
	private const ACTION_TEST_BOTH    = 'fn_print_labels_test_both';

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
		$actions[ self::ACTION_FULL ]         = __( 'Print labels: summary + meal labels', 'fastnutrition-mealprep' );
		$actions[ self::ACTION_SUMMARY ]      = __( 'Print labels: summary only', 'fastnutrition-mealprep' );
		$actions[ self::ACTION_TEST_BOTH ]    = __( 'Test print (no cache): summary + 1 meal', 'fastnutrition-mealprep' );
		$actions[ self::ACTION_TEST_SUMMARY ] = __( 'Test print (no cache): summary only', 'fastnutrition-mealprep' );
		$actions[ self::ACTION_TEST_MEAL ]    = __( 'Test print (no cache): 1 meal label only', 'fastnutrition-mealprep' );
		return $actions;
	}

	/**
	 * @param string  $redirect_to URL WP will redirect to after the bulk action.
	 * @param string  $action      Selected bulk action key.
	 * @param int[]   $order_ids   IDs ticked by the user.
	 */
	public function handle( string $redirect_to, string $action, array $order_ids ): string {
		$real_actions = [ self::ACTION_SUMMARY, self::ACTION_FULL ];
		$test_actions = [ self::ACTION_TEST_SUMMARY, self::ACTION_TEST_MEAL, self::ACTION_TEST_BOTH ];
		if ( ! in_array( $action, array_merge( $real_actions, $test_actions ), true ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}
		$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return add_query_arg( 'fn_labels_result', 'empty', $redirect_to );
		}

		$is_test = in_array( $action, $test_actions, true );
		if ( $is_test ) {
			// Cap test prints to the first ticked order. Otherwise selecting
			// 50 orders + "test print" defeats the purpose.
			$order_ids = [ $order_ids[0] ];
		}

		$mode = match ( $action ) {
			self::ACTION_SUMMARY, self::ACTION_TEST_SUMMARY => LabelPrinter::MODE_SUMMARY,
			self::ACTION_TEST_MEAL                          => LabelPrinter::MODE_MEAL,
			default                                         => LabelPrinter::MODE_FULL,
		};

		// Test prints cap meals at 1 per order; real prints render every meal.
		$meal_limit = $is_test ? 1 : 0;

		LabelPrinter::stream( $order_ids, $mode, $meal_limit );
		// stream() exits.
		return $redirect_to;
	}
}

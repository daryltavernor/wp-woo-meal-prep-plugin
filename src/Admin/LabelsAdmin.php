<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;
use FastNutrition\MealPrep\Labels\LabelPrinter;

final class LabelsAdmin {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_pdf' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_preview' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function maybe_handle_pdf(): void {
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
			return;
		}
		if ( 'fn-print-labels' !== $_GET['page'] || 'pdf' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_print_labels' );

		$start  = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['start'] ) ) : '';
		$end    = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['end'] ) ) : '';
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( (string) $_GET['method'] ) ) : '';

		$order_ids = self::find_orders( $start, $end, $method );
		if ( empty( $order_ids ) ) {
			wp_die( esc_html__( 'No orders matched the filter.', 'fastnutrition-mealprep' ) );
		}
		LabelPrinter::stream( $order_ids );
		// stream() exits.
	}

	/**
	 * Renders the label HTML directly to the browser (no PDF). Cache-busted
	 * via the URL itself + nocache headers. Use this to iterate on label
	 * design without fighting PDF viewer caches: change CSS, hit refresh,
	 * see new design.
	 *
	 * URL: /wp-admin/admin.php?page=fn-print-labels&action=preview&order=X&mode=Y
	 */
	public function maybe_handle_preview(): void {
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
			return;
		}
		if ( 'fn-print-labels' !== $_GET['page'] || 'preview' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_preview_labels' );

		$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		$mode_in  = isset( $_GET['mode'] ) ? sanitize_key( (string) $_GET['mode'] ) : 'full';
		$mode_map = [
			'full'    => LabelPrinter::MODE_FULL,
			'summary' => LabelPrinter::MODE_SUMMARY,
			'meal'    => LabelPrinter::MODE_MEAL,
		];
		$mode = $mode_map[ $mode_in ] ?? LabelPrinter::MODE_FULL;

		if ( ! $order_id || ! wc_get_order( $order_id ) ) {
			wp_die( esc_html__( 'No such order.', 'fastnutrition-mealprep' ) );
		}

		LabelPrinter::stream_html( [ $order_id ], $mode, 1 );
		// stream_html() exits.
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$today = wp_date( 'Y-m-d' );
		$start = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['start'] ) ) : $today;
		$end   = isset( $_GET['end'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['end'] ) )   : wp_date( 'Y-m-d', strtotime( '+7 days', strtotime( $today ) ) );
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( (string) $_GET['method'] ) ) : '';

		$matches = self::find_orders( $start, $end, $method );

		$pdf_url = wp_nonce_url(
			add_query_arg(
				[ 'page' => 'fn-print-labels', 'action' => 'pdf', 'start' => $start, 'end' => $end, 'method' => $method ],
				admin_url( 'admin.php' )
			),
			'fn_print_labels'
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Print Labels', 'fastnutrition-mealprep' ) . '</h1>';

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:900px">';
		echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'What this page does', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Generates a PDF of 100×100 mm thermal labels for every order whose fulfilment date (delivery or collection) falls inside the selected window. Each order produces one summary label (customer, address, contact, total meal count, slot) plus one label per individual meal (description, macros, add-ons, slot).', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:0"><strong>' . esc_html__( 'Branding', 'fastnutrition-mealprep' ) . '</strong> — ';
		echo esc_html__( 'Logo, phone, email, web and return address come from Meal Prep → Settings → Branding.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		// Test preview form — opens a live HTML render in a new tab so the
		// user can iterate on label design without fighting PDF caches.
		$preview_order = isset( $_GET['preview_order'] ) ? (int) $_GET['preview_order'] : 0;
		$preview_mode  = isset( $_GET['preview_mode'] ) ? sanitize_key( (string) $_GET['preview_mode'] ) : 'full';
		echo '<div style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 14px;margin:14px 0;max-width:900px">';
		echo '<p style="margin:0 0 8px"><strong>' . esc_html__( 'Test preview (no cache)', 'fastnutrition-mealprep' ) . '</strong> — ';
		echo esc_html__( 'Renders one order as live HTML in a new tab. Hit refresh in that tab to see the latest design after a code change — no PDF cache to fight.', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<form method="get" target="_blank" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">';
		echo '<input type="hidden" name="page" value="fn-print-labels" />';
		echo '<input type="hidden" name="action" value="preview" />';
		wp_nonce_field( 'fn_preview_labels' );
		printf(
			'<label>%s<br><input type="number" name="order" value="%s" min="1" required style="width:120px" /></label>',
			esc_html__( 'Order #', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $preview_order ?: '' ) )
		);
		echo '<label>' . esc_html__( 'Show', 'fastnutrition-mealprep' ) . '<br><select name="mode">';
		foreach ( [
			'full'    => __( 'Summary + 1 meal label', 'fastnutrition-mealprep' ),
			'summary' => __( 'Summary only', 'fastnutrition-mealprep' ),
			'meal'    => __( '1 meal label only', 'fastnutrition-mealprep' ),
		] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $preview_mode, $val, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Open preview ↗', 'fastnutrition-mealprep' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<form method="get" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="fn-print-labels" />';
		printf(
			'<label>%s <input type="date" name="start" value="%s" /></label> ',
			esc_html__( 'From', 'fastnutrition-mealprep' ),
			esc_attr( $start )
		);
		printf(
			'<label>%s <input type="date" name="end" value="%s" /></label> ',
			esc_html__( 'To', 'fastnutrition-mealprep' ),
			esc_attr( $end )
		);
		echo '<label>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . ' <select name="method">';
		foreach ( [
			''           => __( 'Delivery + Collection', 'fastnutrition-mealprep' ),
			'delivery'   => __( 'Delivery only', 'fastnutrition-mealprep' ),
			'collection' => __( 'Collection only', 'fastnutrition-mealprep' ),
		] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $method, $val, false ), esc_html( $label ) );
		}
		echo '</select></label> ';
		submit_button( __( 'Update', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Matched orders', 'fastnutrition-mealprep' ) . '</h2>';

		if ( empty( $matches ) ) {
			echo '<p><em>' . esc_html__( 'No orders match those filters. Try widening the date range.', 'fastnutrition-mealprep' ) . '</em></p>';
			echo '</div>';
			return;
		}

		$total_meals  = 0;
		$total_labels = 0;

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Fulfilment', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Labels', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $matches as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) {
				continue;
			}
			$ff = $order->get_meta( '_fn_fulfilment' );
			$meals = 0;
			foreach ( $order->get_items() as $item ) {
				$meals += (int) $item->get_quantity();
			}
			$labels        = $meals + 1; // 1 summary + one per meal.
			$total_meals  += $meals;
			$total_labels += $labels;

			$ff_text = '';
			if ( is_array( $ff ) ) {
				$type = (string) ( $ff['type'] ?? '' );
				$date = (string) ( $ff['date'] ?? '' );
				$slot = $ff['slot'] ?? [];
				$ff_text = strtoupper( $type ) . ' · ' . $date;
				if ( ! empty( $slot['start'] ) && ! empty( $slot['end'] ) ) {
					$ff_text .= ' ' . $slot['start'] . '–' . $slot['end'];
				}
			}

			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . (int) $order->get_id() . '</a></td>';
			echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( $ff_text ) . '</td>';
			echo '<td>' . (int) $meals . '</td>';
			echo '<td>' . (int) $labels . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		printf(
			'<p style="margin-top:1em"><strong>%s</strong>: %d %s · %d %s (%d %s + %d %s)</p>',
			esc_html__( 'Totals', 'fastnutrition-mealprep' ),
			count( $matches ),
			esc_html( _n( 'order', 'orders', count( $matches ), 'fastnutrition-mealprep' ) ),
			(int) $total_labels,
			esc_html__( 'labels', 'fastnutrition-mealprep' ),
			count( $matches ),
			esc_html__( 'summaries', 'fastnutrition-mealprep' ),
			(int) $total_meals,
			esc_html__( 'meals', 'fastnutrition-mealprep' )
		);

		echo '<p style="margin-top:1.5em"><a class="button button-primary" href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html__( 'Generate PDF', 'fastnutrition-mealprep' ) . '</a></p>';

		echo '</div>';
	}

	/**
	 * Find orders whose stored fulfilment date falls inside the inclusive range,
	 * optionally filtered by method.
	 *
	 * @return int[] Order IDs in fulfilment-date then ID order.
	 */
	public static function find_orders( string $start, string $end, string $method ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$args = [
			'status'   => PrepOrderStatus::active_statuses(),
			'limit'    => -1,
			'meta_key' => '_fn_fulfilment',
			'orderby'  => 'date',
			'order'    => 'ASC',
		];
		// When the range has a lower bound, no order created before the booking
		// window ahead of $start can fall inside it — so bound the scan by
		// date_created and avoid walking the whole order history. An open-ended
		// range (no $start) keeps the full scan.
		if ( '' !== $start ) {
			$args['date_created'] = '>=' . SlotAvailability::created_since_for_date( $start );
		}
		$orders = wc_get_orders( $args );
		$matched = [];
		foreach ( $orders as $order ) {
			$ff = $order->get_meta( '_fn_fulfilment' );
			if ( ! is_array( $ff ) ) {
				continue;
			}
			$date = (string) ( $ff['date'] ?? '' );
			if ( '' === $date ) {
				continue;
			}
			if ( '' !== $start && $date < $start ) {
				continue;
			}
			if ( '' !== $end && $date > $end ) {
				continue;
			}
			if ( '' !== $method && ( $ff['type'] ?? '' ) !== $method ) {
				continue;
			}
			$slot_start = isset( $ff['slot']['start'] ) ? (string) $ff['slot']['start'] : '';
			$matched[] = [
				'id'    => (int) $order->get_id(),
				'sort'  => $date . ' ' . $slot_start . ' ' . str_pad( (string) $order->get_id(), 10, '0', STR_PAD_LEFT ),
			];
		}
		usort( $matched, static fn( $a, $b ) => strcmp( $a['sort'], $b['sort'] ) );
		return array_map( static fn( $row ) => (int) $row['id'], $matched );
	}
}

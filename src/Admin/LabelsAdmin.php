<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Labels\LabelPrinter;

final class LabelsAdmin {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_pdf' ] );
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
		echo esc_html__( 'Generates a PDF of 4×4 inch thermal labels for every order whose fulfilment date (delivery or collection) falls inside the selected window. Each order produces one summary label (customer, address, contact, total meal count, slot) plus one label per individual meal (description, macros, add-ons, slot).', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:0"><strong>' . esc_html__( 'Branding', 'fastnutrition-mealprep' ) . '</strong> — ';
		echo esc_html__( 'Logo, phone, email, web and return address come from Meal Prep → Settings → Branding.', 'fastnutrition-mealprep' );
		echo '</p></div>';

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
		$orders = wc_get_orders(
			[
				'status'   => [ 'processing', 'completed', 'on-hold' ],
				'limit'    => -1,
				'meta_key' => '_fn_fulfilment',
				'orderby'  => 'date',
				'order'    => 'ASC',
			]
		);
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

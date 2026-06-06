<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\Selection;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;
use FastNutrition\MealPrep\PostTypes\Ingredient;

final class PrepDashboard {

	public function register(): void {
		// Rendering is invoked through MenuRegistry.
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}
		$view = isset( $_GET['view'] ) && 'order' === $_GET['view'] ? 'order' : 'day';

		echo '<div class="wrap"><h1>' . esc_html__( 'Kitchen Prep Dashboard', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:900px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page shows', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Pick a fulfilment date (the date the customer chose for delivery or collection). "By day" aggregates every active order into one list of ingredient portions to prep, which helps the kitchen cut waste. "By order" shows each order individually for packing. Use the "Open printable prep sheet" button for a printer/PDF-friendly version.', 'fastnutrition-mealprep' );
		echo '</p></div>';
		echo '<form method="get" style="margin:1em 0;">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( MenuRegistry::SLUG ) );
		printf( '<label>%s <input type="date" name="date" value="%s" /></label> ', esc_html__( 'Fulfilment date', 'fastnutrition-mealprep' ), esc_attr( $date ) );
		printf( '<label>%s <select name="view">', esc_html__( 'View', 'fastnutrition-mealprep' ) );
		printf( '<option value="day" %s>%s</option>', selected( $view, 'day', false ), esc_html__( 'By day (ingredient totals)', 'fastnutrition-mealprep' ) );
		printf( '<option value="order" %s>%s</option>', selected( $view, 'order', false ), esc_html__( 'By order', 'fastnutrition-mealprep' ) );
		echo '</select></label> ';
		submit_button( __( 'Update', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=fn-prep-sheet&date=' . $date ) ) . '" target="_blank">' . esc_html__( 'Open printable prep sheet', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form>';

		if ( 'order' === $view ) {
			$this->render_order_view( $date );
		} else {
			$this->render_day_view( $date );
		}

		echo '</div>';
	}

	private function render_day_view( string $date ): void {
		$rows = self::get_day_totals( $date );
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No orders scheduled for this date yet.', 'fastnutrition-mealprep' ) . '</em></p>';
			return;
		}
		$grouped = [];
		foreach ( $rows as $row ) {
			$grouped[ $row['type_slug'] ][] = $row;
		}
		$labels = [
			'protein'  => __( 'Proteins', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carbs', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meals', 'fastnutrition-mealprep' ),
			'sweet'    => __( 'Sweets', 'fastnutrition-mealprep' ),
		];
		foreach ( $labels as $slug => $label ) {
			if ( empty( $grouped[ $slug ] ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $label ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Ingredient', 'fastnutrition-mealprep' ) . '</th>';
			echo '<th>' . esc_html__( 'Portions needed', 'fastnutrition-mealprep' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $grouped[ $slug ] as $row ) {
				printf(
					'<tr><td>%s</td><td>%d</td></tr>',
					esc_html( $row['name'] ),
					(int) $row['portions']
				);
			}
			echo '</tbody></table>';
		}
	}

	private function render_order_view( string $date ): void {
		$orders = wc_get_orders(
			[
				'status'   => PrepOrderStatus::active_statuses(),
				'limit'    => -1,
				'meta_key' => '_fn_fulfilment',
			]
		);
		$matched = [];
		foreach ( $orders as $order ) {
			$ff = $order->get_meta( '_fn_fulfilment' );
			if ( is_array( $ff ) && ( $ff['date'] ?? '' ) === $date ) {
				$matched[] = [ 'order' => $order, 'fulfilment' => $ff ];
			}
		}
		if ( empty( $matched ) ) {
			echo '<p><em>' . esc_html__( 'No orders scheduled for this date yet.', 'fastnutrition-mealprep' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Slot', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $matched as $m ) {
			$order = $m['order'];
			$ff    = $m['fulfilment'];
			echo '<tr>';
			printf( '<td><a href="%s">#%d</a></td>', esc_url( $order->get_edit_order_url() ), (int) $order->get_id() );
			echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( (string) ( $ff['type'] ?? '' ) ) . '</td>';
			$slot = $ff['slot'] ?? [];
			echo '<td>' . esc_html( (string) ( $slot['start'] ?? '' ) . '–' . (string) ( $slot['end'] ?? '' ) ) . '</td>';
			echo '<td><ul style="margin:0;padding-left:1em;">';
			foreach ( $order->get_items() as $item ) {
				$sel = $item->get_meta( '_fn_selection', true );
				if ( ! is_array( $sel ) ) {
					continue;
				}
				printf(
					'<li>%d × %s — %s</li>',
					(int) $item->get_quantity(),
					esc_html( $item->get_name() ),
					esc_html( self::describe_selection( $sel ) )
				);
			}
			echo '</ul></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Human-readable meal description. Kept as a thin wrapper around the central
	 * Selection interpreter so existing callers (labels, prep sheet) are unchanged.
	 */
	public static function describe_selection( array $sel ): string {
		return Selection::describe( $sel );
	}

	public static function get_day_totals( string $date ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.ingredient_id, c.portion_count, p.post_title
				 FROM ' . $wpdb->prefix . 'fn_prep_cache c
				 INNER JOIN ' . $wpdb->posts . ' p ON p.ID = c.ingredient_id
				 WHERE c.fulfilment_date = %s
				 ORDER BY c.portion_count DESC',
				$date
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [
				'ingredient_id' => (int) $row['ingredient_id'],
				'name'          => (string) $row['post_title'],
				'portions'      => (int) $row['portion_count'],
				'type_slug'     => Ingredient::get_type_slug( (int) $row['ingredient_id'] ),
			];
		}
		return $out;
	}
}

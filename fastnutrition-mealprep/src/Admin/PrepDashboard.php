<?php
/**
 * Kitchen prep dashboard — per-day ingredient totals + per-order drill-down.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Support\Security;

final class PrepDashboard {

	public function register(): void {
		add_action( 'admin_post_fn_export_prep_csv', [ $this, 'export_csv' ] );
	}

	public function render(): void {
		Security::require_manage();
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$method = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : '';
		$tab    = isset( $_GET['tab'] ) && in_array( $_GET['tab'], [ 'by-day', 'by-order' ], true ) ? $_GET['tab'] : 'by-day';

		$totals = OrderItemMeta::aggregate_portions( $date, $method ?: null );

		?>
		<div class="wrap fn-prep-dashboard">
			<h1><?php esc_html_e( 'Meal Prep Dashboard', 'fastnutrition-mealprep' ); ?></h1>

			<form method="get" style="margin: 16px 0;">
				<input type="hidden" name="page" value="fn-prep-dashboard">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<label><?php esc_html_e( 'Date', 'fastnutrition-mealprep' ); ?>
					<input type="date" name="date" value="<?php echo esc_attr( $date ); ?>">
				</label>
				<label><?php esc_html_e( 'Method', 'fastnutrition-mealprep' ); ?>
					<select name="method">
						<option value=""><?php esc_html_e( 'All', 'fastnutrition-mealprep' ); ?></option>
						<option value="delivery" <?php selected( $method, 'delivery' ); ?>><?php esc_html_e( 'Delivery', 'fastnutrition-mealprep' ); ?></option>
						<option value="collection" <?php selected( $method, 'collection' ); ?>><?php esc_html_e( 'Collection', 'fastnutrition-mealprep' ); ?></option>
					</select>
				</label>
				<button class="button button-primary"><?php esc_html_e( 'Filter', 'fastnutrition-mealprep' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fn-prep-sheet&date=' . $date . '&method=' . $method ) ); ?>"><?php esc_html_e( 'Printable prep sheet', 'fastnutrition-mealprep' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fn_export_prep_csv&date=' . $date . '&method=' . $method ), 'fn_prep_csv' ) ); ?>"><?php esc_html_e( 'Export CSV', 'fastnutrition-mealprep' ); ?></a>
			</form>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'by-day' ) ); ?>" class="nav-tab <?php echo 'by-day' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'By day', 'fastnutrition-mealprep' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'by-order' ) ); ?>" class="nav-tab <?php echo 'by-order' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'By order', 'fastnutrition-mealprep' ); ?></a>
			</h2>

			<?php if ( 'by-day' === $tab ) : ?>
				<?php $this->render_totals( $totals ); ?>
			<?php else : ?>
				<?php $this->render_by_order( $date, $method ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_totals( array $totals ): void {
		foreach ( $totals as $type => $rows ) {
			if ( empty( $rows ) ) {
				continue;
			}
			echo '<h3>' . esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . '</h3>';
			echo '<table class="widefat striped" style="max-width: 520px;"><thead><tr><th>' . esc_html__( 'Ingredient', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Portions', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r['title'] ) . '</td><td>' . (int) $r['portions'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function render_by_order( string $date, string $method ): void {
		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'status'     => [ 'processing', 'on-hold', 'completed' ],
				'meta_query' => [
					[ 'key' => '_fn_fulfilment', 'compare' => 'EXISTS' ],
				],
			]
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Slot', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$f = $order->get_meta( '_fn_fulfilment' );
			if ( empty( $f ) || ( $f['date'] ?? '' ) !== $date ) {
				continue;
			}
			if ( $method && ( $f['type'] ?? '' ) !== $method ) {
				continue;
			}
			$meals = [];
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$sel = $item->get_meta( OrderItemMeta::ORDER_ITEM_SELECTION );
				if ( ! $sel ) {
					continue;
				}
				$meals[] = esc_html( $item->get_name() . ' × ' . $item->get_quantity() );
			}
			echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . (int) $order->get_id() . '</a></td>';
			echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( (string) ( $f['type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $f['slot']['start'] ?? '' ) . '–' . ( $f['slot']['end'] ?? '' ) ) . '</td>';
			echo '<td>' . wp_kses_post( implode( '<br>', $meals ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function export_csv(): void {
		Security::require_manage();
		check_admin_referer( 'fn_prep_csv' );
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : gmdate( 'Y-m-d' );
		$method = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : '';
		$totals = OrderItemMeta::aggregate_portions( $date, $method ?: null );

		nocache_headers();
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="prep-' . $date . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, [ 'Type', 'Ingredient', 'Portions' ] );
		foreach ( $totals as $type => $rows ) {
			foreach ( $rows as $r ) {
				fputcsv( $fh, [ $type, $r['title'], $r['portions'] ] );
			}
		}
		fclose( $fh );
		exit;
	}
}

<?php
/**
 * Printable kitchen prep sheet.
 *
 * Renders (1) aggregated ingredient totals, (2) per-order pick list with allergen flags,
 * and (3) a delivery run sheet grouped by profile → postcode → slot.
 *
 * Supports ?download=pdf to emit via Dompdf if available, otherwise print-CSS fallback.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Support\Security;

final class PrepSheet {

	public function register(): void {
		// Rendered via the admin menu.
	}

	public function render(): void {
		Security::require_manage();
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$method = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : '';

		if ( ! empty( $_GET['download'] ) && 'pdf' === $_GET['download'] ) {
			$this->download_pdf( $date, $method );
			return;
		}

		$totals = OrderItemMeta::aggregate_portions( $date, $method ?: null );
		$orders = $this->fetch_orders( $date, $method );

		?>
		<div class="wrap fn-prep-sheet">
			<h1 class="screen-only"><?php esc_html_e( 'Printable Prep Sheet', 'fastnutrition-mealprep' ); ?></h1>

			<form method="get" class="screen-only" style="margin: 16px 0;">
				<input type="hidden" name="page" value="fn-prep-sheet">
				<label><?php esc_html_e( 'Date', 'fastnutrition-mealprep' ); ?> <input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" required></label>
				<label><?php esc_html_e( 'Method', 'fastnutrition-mealprep' ); ?>
					<select name="method">
						<option value=""><?php esc_html_e( 'All', 'fastnutrition-mealprep' ); ?></option>
						<option value="delivery" <?php selected( $method, 'delivery' ); ?>><?php esc_html_e( 'Delivery', 'fastnutrition-mealprep' ); ?></option>
						<option value="collection" <?php selected( $method, 'collection' ); ?>><?php esc_html_e( 'Collection', 'fastnutrition-mealprep' ); ?></option>
					</select>
				</label>
				<button class="button button-primary"><?php esc_html_e( 'Update', 'fastnutrition-mealprep' ); ?></button>
				<button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Print', 'fastnutrition-mealprep' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'download', 'pdf' ) ); ?>"><?php esc_html_e( 'Download PDF', 'fastnutrition-mealprep' ); ?></a>
			</form>

			<?php echo $this->render_body( $date, $method, $totals, $orders ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<style>
				.fn-prep-sheet .sheet-title { font-size: 22px; margin: 0 0 4px; }
				.fn-prep-sheet h2 { border-bottom: 2px solid #000; padding-bottom: 4px; margin-top: 32px; }
				.fn-prep-sheet table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
				.fn-prep-sheet th, .fn-prep-sheet td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 13px; }
				.fn-prep-sheet .pick-card { border: 1px solid #000; padding: 8px; margin-bottom: 10px; page-break-inside: avoid; }
				.fn-prep-sheet .pack-box { display: inline-block; width: 14px; height: 14px; border: 1px solid #000; vertical-align: middle; margin-right: 6px; }
				.fn-prep-sheet .allergens { color: #b00; font-size: 11px; }
				@media print {
					.screen-only, #adminmenumain, #wpfooter, #wpadminbar { display: none !important; }
					#wpcontent, #wpbody-content { margin-left: 0 !important; padding: 0 !important; }
					.fn-prep-sheet { font-family: Arial, sans-serif; color: #000; }
				}
			</style>
		</div>
		<?php
	}

	/**
	 * Produce the sheet body HTML (reused for screen + PDF output).
	 */
	private function render_body( string $date, string $method, array $totals, array $orders ): string {
		ob_start();
		$label = $method ? ucfirst( $method ) : __( 'All methods', 'fastnutrition-mealprep' );
		?>
		<p class="sheet-title"><strong><?php echo esc_html( sprintf( __( 'Prep sheet — %1$s — %2$s', 'fastnutrition-mealprep' ), $date, $label ) ); ?></strong></p>

		<h2><?php esc_html_e( 'Ingredient totals', 'fastnutrition-mealprep' ); ?></h2>
		<?php foreach ( $totals as $type => $rows ) : ?>
			<?php if ( empty( $rows ) ) { continue; } ?>
			<h3><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></h3>
			<table>
				<thead><tr><th><?php esc_html_e( 'Ingredient', 'fastnutrition-mealprep' ); ?></th><th><?php esc_html_e( 'Allergens', 'fastnutrition-mealprep' ); ?></th><th><?php esc_html_e( 'Portions', 'fastnutrition-mealprep' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php $ing = Ingredient::get( (int) $r['ingredient_id'] ); ?>
						<tr>
							<td><?php echo esc_html( $r['title'] ); ?></td>
							<td class="allergens"><?php echo esc_html( implode( ', ', $ing['allergens'] ?? [] ) ); ?></td>
							<td><?php echo (int) $r['portions']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Per-order pick list', 'fastnutrition-mealprep' ); ?></h2>
		<?php foreach ( $orders as $entry ) : ?>
			<div class="pick-card">
				<strong>#<?php echo (int) $entry['order_id']; ?> — <?php echo esc_html( $entry['customer'] ); ?></strong>
				&nbsp;|&nbsp; <?php echo esc_html( $entry['method'] ); ?>
				&nbsp;|&nbsp; <?php echo esc_html( $entry['slot'] ); ?>
				<?php if ( $entry['address'] ) : ?>
					&nbsp;|&nbsp; <?php echo esc_html( $entry['address'] ); ?>
				<?php endif; ?>
				<ul style="margin: 6px 0 0 0;">
					<?php foreach ( $entry['meals'] as $meal ) : ?>
						<li><span class="pack-box"></span><?php echo esc_html( $meal ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>

		<?php if ( 'delivery' === $method || '' === $method ) : ?>
			<?php $run = $this->delivery_run_sheet( $orders ); ?>
			<?php if ( ! empty( $run ) ) : ?>
				<h2><?php esc_html_e( 'Delivery run sheet', 'fastnutrition-mealprep' ); ?></h2>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Profile', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Postcode', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Slot', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Meals', 'fastnutrition-mealprep' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $run as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['profile'] ); ?></td>
								<td><?php echo esc_html( $row['postcode'] ); ?></td>
								<td><?php echo esc_html( $row['slot'] ); ?></td>
								<td><?php echo (int) $row['orders']; ?></td>
								<td><?php echo (int) $row['meals']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	private function fetch_orders( string $date, string $method ): array {
		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'status'     => [ 'processing', 'on-hold', 'completed' ],
				'meta_query' => [
					[ 'key' => '_fn_fulfilment', 'compare' => 'EXISTS' ],
				],
			]
		);
		$out = [];
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
				$selection = $item->get_meta( OrderItemMeta::ORDER_ITEM_SELECTION );
				$detail    = $item->get_name() . ' × ' . $item->get_quantity();
				if ( is_array( $selection ) ) {
					$sub = [];
					if ( 'set' === ( $selection['mode'] ?? '' ) && ! empty( $selection['set_meal_id'] ) ) {
						$ing = Ingredient::get( (int) $selection['set_meal_id'] );
						$sub[] = $ing['title'] ?? '';
					} else {
						foreach ( [ 'protein_id', 'carb_id' ] as $k ) {
							if ( ! empty( $selection[ $k ] ) ) {
								$ing = Ingredient::get( (int) $selection[ $k ] );
								$sub[] = $ing['title'] ?? '';
							}
						}
						foreach ( (array) ( $selection['greens_ids'] ?? [] ) as $gid ) {
							$ing = Ingredient::get( (int) $gid );
							$sub[] = $ing['title'] ?? '';
						}
					}
					if ( ! empty( $selection['addons'] ) ) {
						$sub[] = '+ ' . implode( ', ', array_column( $selection['addons'], 'label' ) );
					}
					$detail .= ' — ' . implode( ', ', array_filter( $sub ) );
				}
				$meals[] = $detail;
			}
			$out[] = [
				'order_id' => $order->get_id(),
				'customer' => $order->get_formatted_billing_full_name(),
				'method'   => ucfirst( (string) ( $f['type'] ?? '' ) ),
				'slot'     => sprintf( '%s %s–%s', $f['date'] ?? '', $f['slot']['start'] ?? '', $f['slot']['end'] ?? '' ),
				'address'  => 'delivery' === ( $f['type'] ?? '' ) ? $order->get_shipping_address_1() . ', ' . $order->get_shipping_postcode() : '',
				'profile'  => (int) ( $f['profile_id'] ?? 0 ),
				'postcode' => $order->get_shipping_postcode(),
				'meals'    => $meals,
			];
		}
		return $out;
	}

	private function delivery_run_sheet( array $orders ): array {
		$groups = [];
		foreach ( $orders as $o ) {
			if ( 'Delivery' !== $o['method'] ) {
				continue;
			}
			$key = $o['profile'] . '|' . $o['postcode'] . '|' . $o['slot'];
			if ( ! isset( $groups[ $key ] ) ) {
				$profile_name = '';
				$p = \FastNutrition\MealPrep\Delivery\Profile::find( (int) $o['profile'] );
				if ( $p ) {
					$profile_name = $p['name'];
				}
				$groups[ $key ] = [
					'profile'  => $profile_name ?: '#' . $o['profile'],
					'postcode' => $o['postcode'],
					'slot'     => $o['slot'],
					'orders'   => 0,
					'meals'    => 0,
				];
			}
			$groups[ $key ]['orders']++;
			$groups[ $key ]['meals'] += count( $o['meals'] );
		}
		return array_values( $groups );
	}

	private function download_pdf( string $date, string $method ): void {
		$totals = OrderItemMeta::aggregate_portions( $date, $method ?: null );
		$orders = $this->fetch_orders( $date, $method );
		$body   = '<html><head><style>body{font-family:Arial,sans-serif;color:#000;}table{width:100%;border-collapse:collapse;margin-bottom:10px;}th,td{border:1px solid #ccc;padding:4px 6px;font-size:11px;}h2{border-bottom:1px solid #000;}</style></head><body class="fn-prep-sheet">' . $this->render_body( $date, $method, $totals, $orders ) . '</body></html>';

		if ( class_exists( \Dompdf\Dompdf::class ) ) {
			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $body );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			$dompdf->stream( 'prep-' . $date . '.pdf', [ 'Attachment' => true ] );
			exit;
		}

		// Fallback: serve HTML with print headers so the browser can save to PDF.
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}

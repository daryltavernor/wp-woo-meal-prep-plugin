<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Labels;

use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Macros\Calculator;

/**
 * Generates a print-ready PDF of 4x4-inch labels for a thermal printer.
 *
 * For each matched order:
 *   1. ONE summary label  — customer name, address, contact, total meal count, fulfilment slot, brand contact.
 *   2. N meal labels      — one per individual meal (qty), with meal description, macros, add-ons,
 *                           fulfilment slot, and brand contact.
 *
 * Page size is 4x4 in (288x288 pt). Each label is its own page so the
 * thermal printer cuts/dispenses between them automatically.
 */
final class LabelPrinter {

	public const MODE_FULL    = 'full';
	public const MODE_SUMMARY = 'summary';

	/**
	 * Stream a labels PDF to the browser and exit.
	 *
	 * @param int[]  $order_ids Order IDs to render.
	 * @param string $mode      MODE_FULL (one summary + one per meal) or MODE_SUMMARY (summary only).
	 */
	public static function stream( array $order_ids, string $mode = self::MODE_FULL ): void {
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install in the plugin directory.', 'fastnutrition-mealprep' ) );
		}
		$html   = self::build_html( $order_ids, $mode );
		$dompdf = new \Dompdf\Dompdf(
			[
				'isRemoteEnabled'      => true,
				'isPhpEnabled'         => false,
				'defaultMediaType'     => 'print',
				'isHtml5ParserEnabled' => true,
			]
		);
		$dompdf->loadHtml( $html );
		// 4 in x 4 in = 288 pt x 288 pt (1 in = 72 pt).
		$dompdf->setPaper( [ 0, 0, 288, 288 ], 'portrait' );
		$dompdf->render();
		$prefix   = self::MODE_SUMMARY === $mode ? 'summary-labels' : 'labels';
		$filename = $prefix . '-' . gmdate( 'Y-m-d-His' ) . '.pdf';
		$dompdf->stream( $filename, [ 'Attachment' => false ] );
		exit;
	}

	private static function build_html( array $order_ids, string $mode = self::MODE_FULL ): string {
		$brand = SettingsPage::brand_info();
		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Labels</title>
<style>
	/* Thermal-printer design notes:
	   - Monochrome only (no greys). Every colour is #000 on #fff or inverted.
	     Mid-tone greys dither badly on direct-thermal heads.
	   - 5mm safety margin on all sides — exceeds the 3mm bleed required by the
	     rounded-corner cut so no content lands in the trimmed zone.
	   - Inverted blocks (white text on solid black) are drawn as solid ink and
	     print cleanly. Used sparingly for triage cues (UNPAID, meal count). */
	@page { size: 4in 4in; margin: 0; }
	html, body { margin: 0; padding: 0; }
	body { font-family: DejaVu Sans, sans-serif; color: #000; }
	.label {
		width: 4in;
		height: 4in;
		box-sizing: border-box;
		padding: 5mm;
		page-break-after: always;
		position: relative;
		overflow: hidden;
	}
	.label:last-child { page-break-after: auto; }
	.lbl-head {
		border-bottom: 1px solid #000;
		padding-bottom: 2mm;
		margin-bottom: 2mm;
	}
	.lbl-head .lbl-logo {
		max-width: 38mm;
		max-height: 12mm;
		vertical-align: middle;
	}
	.lbl-head .lbl-order {
		float: right;
		font-size: 11pt;
		font-weight: bold;
		text-align: right;
		line-height: 1.2;
	}
	.lbl-head .lbl-order .lbl-counter {
		display: block;
		font-size: 7pt;
		font-weight: normal;
		color: #000;
	}
	.lbl-name { font-size: 11pt; font-weight: bold; margin-bottom: 1mm; }
	.lbl-desc { font-size: 12pt; font-weight: bold; line-height: 1.2; margin-bottom: 1.5mm; }
	.lbl-addons { font-size: 8pt; font-style: italic; color: #000; margin-bottom: 2mm; }
	.lbl-macros {
		font-size: 9pt;
		border: 1px solid #000;
		padding: 1.5mm 2mm;
		margin-bottom: 2mm;
	}
	.lbl-macros span { display: inline-block; margin-right: 2.5mm; }
	.lbl-macros strong { font-weight: bold; }
	.lbl-address { font-size: 9pt; line-height: 1.35; margin-bottom: 2mm; }
	.lbl-customer-contact { font-size: 8pt; color: #000; margin-bottom: 2mm; }
	.lbl-count {
		text-align: center;
		font-size: 22pt;
		font-weight: bold;
		letter-spacing: 0.5mm;
		padding: 2mm 0;
		margin: 1mm 0 2mm;
		border-top: 1px solid #000;
		border-bottom: 1px solid #000;
	}
	.lbl-fulfilment {
		font-size: 9pt;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.2mm;
		margin-bottom: 2mm;
	}
	/* Payment status — asymmetric on purpose.
	   PAID = quiet thin-ruled line. UNPAID = full-width inverted bar
	   so a packer can never miss an unpaid order. */
	.lbl-payment {
		text-align: center;
		margin-bottom: 2mm;
	}
	.lbl-payment--paid {
		font-size: 9pt;
		padding: 1mm 0;
		border-top: 1px solid #000;
		border-bottom: 1px solid #000;
	}
	.lbl-payment--paid .lbl-payment-badge {
		font-weight: bold;
		letter-spacing: 0.5mm;
	}
	.lbl-payment--paid .lbl-payment-method {
		margin-left: 2mm;
	}
	.lbl-payment--unpaid {
		background: #000;
		color: #fff;
		padding: 1.5mm 0;
	}
	.lbl-payment--unpaid .lbl-payment-badge {
		display: block;
		font-size: 14pt;
		font-weight: bold;
		letter-spacing: 1mm;
	}
	.lbl-payment--unpaid .lbl-payment-method {
		display: block;
		font-size: 8pt;
		margin-top: 0.5mm;
	}
	.lbl-foot {
		position: absolute;
		bottom: 5mm;
		left: 5mm;
		right: 5mm;
		font-size: 7pt;
		line-height: 1.35;
		color: #000;
		border-top: 1px solid #000;
		padding-top: 1.5mm;
	}
	.lbl-foot strong { color: #000; }
	.muted { color: #000; font-style: italic; }
</style>
</head>
<body>
		<?php
		$has_labels = false;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}
			self::render_summary_label( $order, $brand );
			$has_labels = true;
			if ( self::MODE_SUMMARY === $mode ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				$qty = (int) $item->get_quantity();
				for ( $i = 1; $i <= $qty; $i++ ) {
					self::render_meal_label( $order, $item, $i, $qty, $brand );
				}
			}
		}
		if ( ! $has_labels ) {
			echo '<div class="label"><p>' . esc_html__( 'No orders matched the filter.', 'fastnutrition-mealprep' ) . '</p></div>';
		}
		?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_summary_label( \WC_Order $order, array $brand ): void {
		$ff           = $order->get_meta( '_fn_fulfilment' );
		$total_meals  = 0;
		foreach ( $order->get_items() as $item ) {
			$total_meals += (int) $item->get_quantity();
		}
		$ship_address = $order->get_formatted_shipping_address();
		$bill_address = $order->get_formatted_billing_address();
		$address      = $ship_address ?: $bill_address;
		$is_collection = is_array( $ff ) && 'collection' === ( $ff['type'] ?? '' );
		?>
		<div class="label summary">
			<?php self::render_head( $order, $brand, null ); ?>
			<div class="lbl-name"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></div>
			<div class="lbl-address"><?php echo $is_collection ? '<em class="muted">' . esc_html__( 'Collection — no delivery address', 'fastnutrition-mealprep' ) . '</em>' : wp_kses_post( $address ); ?></div>
			<div class="lbl-customer-contact">
				<?php if ( $order->get_billing_phone() ) : ?>
					<strong><?php esc_html_e( 'Tel:', 'fastnutrition-mealprep' ); ?></strong> <?php echo esc_html( $order->get_billing_phone() ); ?>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<?php if ( $order->get_billing_phone() ) : ?> &nbsp; <?php endif; ?>
					<strong><?php esc_html_e( 'Email:', 'fastnutrition-mealprep' ); ?></strong> <?php echo esc_html( $order->get_billing_email() ); ?>
				<?php endif; ?>
			</div>
			<?php
			$is_paid       = $order->is_paid();
			$payment_title = trim( (string) $order->get_payment_method_title() );
			?>
			<div class="lbl-payment lbl-payment--<?php echo $is_paid ? 'paid' : 'unpaid'; ?>">
				<span class="lbl-payment-badge"><?php echo $is_paid ? esc_html__( 'PAID', 'fastnutrition-mealprep' ) : esc_html__( 'UNPAID', 'fastnutrition-mealprep' ); ?></span>
				<?php if ( '' !== $payment_title ) : ?>
					<span class="lbl-payment-method">
						<?php
						/* translators: %s: human-readable payment method name (e.g. "Credit Card (Stripe)") */
						printf( esc_html__( 'via %s', 'fastnutrition-mealprep' ), esc_html( $payment_title ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="lbl-count">
				<?php
				/* translators: %d: total meal count */
				printf( esc_html( _n( '%d MEAL', '%d MEALS', $total_meals, 'fastnutrition-mealprep' ) ), (int) $total_meals );
				?>
			</div>
			<div class="lbl-fulfilment"><?php echo esc_html( self::format_fulfilment( $ff ) ); ?></div>
			<?php self::render_foot( $brand ); ?>
		</div>
		<?php
	}

	private static function render_meal_label( \WC_Order $order, \WC_Order_Item_Product $item, int $idx, int $total, array $brand ): void {
		$selection = $item->get_meta( '_fn_selection', true );
		if ( ! is_array( $selection ) ) {
			// Fall back to the cart-attached selection key for older items.
			$selection = $item->get_meta( Selections::CART_KEY, true );
		}
		$desc   = is_array( $selection ) ? PrepDashboard::describe_selection( $selection ) : '';
		if ( '' === $desc ) {
			$desc = $item->get_name();
		}
		$macros = is_array( $selection ) ? Calculator::macros_for_selection( (int) $item->get_product_id(), $selection ) : Calculator::EMPTY;
		$addons = is_array( $selection ) && ! empty( $selection['addons'] ) ? array_filter( array_map( static fn( $a ) => (string) ( $a['label'] ?? '' ), (array) $selection['addons'] ) ) : [];
		$ff     = $order->get_meta( '_fn_fulfilment' );
		?>
		<div class="label meal">
			<?php self::render_head( $order, $brand, $idx . '/' . $total ); ?>
			<div class="lbl-name"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></div>
			<div class="lbl-desc"><?php echo esc_html( $desc ); ?></div>
			<?php if ( ! empty( $addons ) ) : ?>
				<div class="lbl-addons">+ <?php echo esc_html( implode( ', ', $addons ) ); ?></div>
			<?php endif; ?>
			<div class="lbl-macros">
				<span><strong>K</strong> <?php echo (int) round( (float) $macros['kcal'] ); ?></span>
				<span><strong>P</strong> <?php echo (int) round( (float) $macros['protein_g'] ); ?>g</span>
				<span><strong>C</strong> <?php echo (int) round( (float) $macros['carbs_g'] ); ?>g</span>
				<span><strong>F</strong> <?php echo (int) round( (float) $macros['fat_g'] ); ?>g</span>
			</div>
			<div class="lbl-fulfilment"><?php echo esc_html( self::format_fulfilment( $ff ) ); ?></div>
			<?php self::render_foot( $brand ); ?>
		</div>
		<?php
	}

	private static function render_head( \WC_Order $order, array $brand, ?string $counter ): void {
		?>
		<div class="lbl-head">
			<div class="lbl-order">#<?php echo (int) $order->get_id(); ?>
				<?php if ( null !== $counter ) : ?>
					<span class="lbl-counter"><?php echo esc_html( $counter ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $brand['logo_path'] ) ) : ?>
				<img src="<?php echo esc_attr( $brand['logo_path'] ); ?>" class="lbl-logo" alt="" />
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_foot( array $brand ): void {
		$bits = array_filter( [
			$brand['web'],
			$brand['email'],
			$brand['phone'],
		] );
		if ( empty( $bits ) ) {
			return;
		}
		?>
		<div class="lbl-foot">
			<?php echo esc_html( implode( ' · ', $bits ) ); ?>
		</div>
		<?php
	}

	private static function format_fulfilment( mixed $ff ): string {
		if ( ! is_array( $ff ) ) {
			return '';
		}
		$type = strtoupper( (string) ( $ff['type'] ?? '' ) );
		$date = (string) ( $ff['date'] ?? '' );
		$slot = $ff['slot'] ?? [];
		$start = (string) ( $slot['start'] ?? '' );
		$end   = (string) ( $slot['end'] ?? '' );
		$pretty_date = $date ? wp_date( 'D j M', strtotime( $date ) ) : '';
		$window = ( $start && $end ) ? ( $start . '–' . $end ) : '';
		return trim( $type . ' · ' . trim( $pretty_date . ' ' . $window ) );
	}
}

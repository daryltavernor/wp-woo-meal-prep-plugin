<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Labels;

use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Macros\Calculator;

/**
 * Generates a print-ready PDF of 100x100 mm labels for a thermal printer.
 *
 * For each matched order:
 *   1. ONE summary label  — customer name, address, contact, total meal count, fulfilment slot, brand contact.
 *   2. N meal labels      — one per individual meal (qty), with meal description, macros, add-ons,
 *                           fulfilment slot, and brand contact.
 *
 * Page size is 100x100 mm (~283.46 pt square). Each label is its own page so
 * the thermal printer cuts/dispenses between them automatically.
 */
final class LabelPrinter {

	public const MODE_FULL    = 'full';
	public const MODE_SUMMARY = 'summary';

	/** Physical label stock — 100 mm square direct-thermal. */
	private const LABEL_SIDE_MM = 100.0;

	/** Print head resolution. 203 dpi = 8 dots per mm; a 100 mm side is 800 dots. */
	private const PRINTER_DPI = 203;

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
				// Match the print head so embedded images (the brand logo) are
				// sampled at the device resolution rather than Dompdf's 96 dpi
				// screen default. Without this the logo prints soft.
				'dpi'                  => self::PRINTER_DPI,
				// Allow Dompdf to read local files from the WP install (e.g. the
				// uploaded brand logo in wp-content/uploads). Without this,
				// Dompdf's default chroot is the vendor dir and the logo silently
				// renders as the broken-image placeholder.
				'chroot'               => [ ABSPATH, WP_CONTENT_DIR ],
			]
		);
		$dompdf->loadHtml( $html );
		// Convert the mm side to points (1 pt = 1/72 in, 1 in = 25.4 mm).
		$side_pt = self::LABEL_SIDE_MM * 72 / 25.4;
		$dompdf->setPaper( [ 0, 0, $side_pt, $side_pt ], 'portrait' );
		$dompdf->render();
		$prefix   = self::MODE_SUMMARY === $mode ? 'summary-labels' : 'labels';
		$filename = $prefix . '-' . gmdate( 'Y-m-d-His' ) . '.pdf';
		$dompdf->stream( $filename, [ 'Attachment' => false ] );
		exit;
	}

	private static function build_html( array $order_ids, string $mode = self::MODE_FULL ): string {
		$brand                  = SettingsPage::brand_info();
		$brand['logo_data_uri'] = self::logo_data_uri( $brand );
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
	@page { size: 100mm 100mm; margin: 0; }
	html, body { margin: 0; padding: 0; }
	body { font-family: DejaVu Sans, sans-serif; color: #000; }
	/* Page-break strategy: each .label is exactly page-sized. Instead of
	   page-break-after (which Dompdf turns into an extra blank page when the
	   label fills the page exactly), put the break BEFORE each label after
	   the first. No trailing break, no blank pages between labels.
	   Bottom padding reserves ~22 mm for the brand foot (address +
	   3 lines of contact). */
	.label {
		width: 100mm;
		height: 100mm;
		box-sizing: border-box;
		padding: 5mm 5mm 27mm 5mm;
		position: relative;
		overflow: hidden;
	}
	.label + .label { page-break-before: always; }
	/* Head holds the logo on the left. The counter ("1/10") sits on the
	   right for meal labels and is empty on the summary. Order # has
	   moved out of the head — it now sits inline with the customer
	   name to save horizontal space. */
	.lbl-head {
		width: 100%;
		border-collapse: collapse;
		border-bottom: 1px solid #000;
		margin: 0 0 2mm;
	}
	.lbl-head td {
		padding: 0 0 2mm;
		vertical-align: middle;
	}
	.lbl-head-logo img {
		max-width: 55mm;
		max-height: 14mm;
	}
	.lbl-head-counter {
		text-align: right;
		font-size: 10pt;
		font-weight: bold;
		width: 22mm;
	}
	.lbl-name {
		font-size: 12pt;
		font-weight: bold;
		margin-bottom: 1.5mm;
		line-height: 1.2;
	}
	.lbl-name-id { margin-right: 2mm; }
	.lbl-desc {
		font-size: 12pt;
		font-weight: bold;
		line-height: 1.2;
		margin-bottom: 1.5mm;
		word-wrap: break-word;
		overflow-wrap: break-word;
	}
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
		font-size: 8pt;
		line-height: 1.35;
		color: #000;
		border-top: 1px solid #000;
		padding-top: 1.5mm;
		text-align: center;
	}
	.lbl-foot-address { font-weight: bold; margin-bottom: 0.8mm; }
	.lbl-foot-line { line-height: 1.35; }
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
			<?php self::render_head( $brand, null ); ?>
			<div class="lbl-name">
				<span class="lbl-name-id">#<?php echo (int) $order->get_id(); ?></span>
				<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
			</div>
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
			$payment_status = self::payment_status( $order );
			$is_paid        = 'paid' === $payment_status['state'];
			$payment_title  = $payment_status['title'];
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
			<?php self::render_head( $brand, $idx . '/' . $total ); ?>
			<div class="lbl-name">
				<span class="lbl-name-id">#<?php echo (int) $order->get_id(); ?></span>
				<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
			</div>
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
			<?php if ( $order->get_billing_phone() ) : ?>
				<div class="lbl-customer-contact">
					<strong><?php esc_html_e( 'Tel:', 'fastnutrition-mealprep' ); ?></strong> <?php echo esc_html( $order->get_billing_phone() ); ?>
				</div>
			<?php endif; ?>
			<div class="lbl-fulfilment"><?php echo esc_html( self::format_fulfilment( $ff ) ); ?></div>
			<?php self::render_foot( $brand ); ?>
		</div>
		<?php
	}

	private static function render_head( array $brand, ?string $counter ): void {
		$logo_src = (string) ( $brand['logo_data_uri'] ?? '' );
		?>
		<table class="lbl-head">
			<tr>
				<td class="lbl-head-logo">
					<?php if ( '' !== $logo_src ) : ?>
						<img src="<?php echo esc_attr( $logo_src ); ?>" alt="" />
					<?php endif; ?>
				</td>
				<td class="lbl-head-counter"><?php echo null !== $counter ? esc_html( $counter ) : ''; ?></td>
			</tr>
		</table>
		<?php
	}

	private static function render_foot( array $brand ): void {
		$web     = trim( (string) ( $brand['web'] ?? '' ) );
		$email   = trim( (string) ( $brand['email'] ?? '' ) );
		$phone   = trim( (string) ( $brand['phone'] ?? '' ) );
		$address = trim( (string) ( $brand['address'] ?? '' ) );
		if ( '' === $web && '' === $email && '' === $phone && '' === $address ) {
			return;
		}
		?>
		<div class="lbl-foot">
			<?php if ( '' !== $address ) : ?>
				<div class="lbl-foot-address"><?php echo nl2br( esc_html( $address ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $web ) : ?>
				<div class="lbl-foot-line"><?php echo esc_html( $web ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $email ) : ?>
				<div class="lbl-foot-line"><?php echo esc_html( $email ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $phone ) : ?>
				<div class="lbl-foot-line"><?php echo esc_html( $phone ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Inline the brand logo as a base64 data URI so Dompdf never has to touch
	 * the filesystem (which can fail silently due to chroot or open_basedir).
	 */
	private static function logo_data_uri( array $brand ): string {
		$path = (string) ( $brand['logo_path'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		$data = @file_get_contents( $path );
		if ( false === $data ) {
			return '';
		}
		$type = wp_check_filetype( $path );
		$mime = $type['type'] ?? '';
		// Dompdf reliably handles PNG, JPEG and GIF. SVG/WebP/AVIF are hit-or-miss.
		if ( ! in_array( $mime, [ 'image/png', 'image/jpeg', 'image/gif' ], true ) ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $data );
	}

	/**
	 * Resolve the payment state for a label. Cash-on-delivery / collection is
	 * always "unpaid" until the courier or counter staff actually collect the
	 * money — even though WooCommerce marks the order as "processing" and
	 * WC_Order::is_paid() returns true.
	 *
	 * @return array{state:string,title:string}
	 */
	private static function payment_status( \WC_Order $order ): array {
		$method_id = $order->get_payment_method();
		$title     = trim( (string) $order->get_payment_method_title() );

		/**
		 * Payment-method slugs that should never appear as PAID on the label,
		 * because the money is collected at fulfilment rather than upfront.
		 * Defaults to WooCommerce's built-in COD gateway.
		 *
		 * @param string[] $methods
		 */
		$cash_methods = (array) apply_filters( 'fn_mealprep_cash_payment_methods', [ 'cod' ] );

		$is_cash = in_array( $method_id, $cash_methods, true );
		$is_paid = ! $is_cash && $order->is_paid();

		return [
			'state' => $is_paid ? 'paid' : 'unpaid',
			'title' => $title,
		];
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

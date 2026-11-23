<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Invoices;

use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\Cart\Selection;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Checkout\FulfilmentDisplay;
use FastNutrition\MealPrep\Labels\LabelPrinter;

/**
 * Generates a print-ready A4 customer invoice PDF.
 *
 * One invoice per order, one order per page (spilling to a second page only
 * when the item list is too long to fit — the items table header repeats so a
 * continuation page still reads as part of the same invoice).
 *
 * Each invoice carries:
 *   - brand header (logo + company name, address, phone, email, web) from
 *     Meal Prep → Settings → Branding, exactly like labels and prep sheets,
 *   - invoice/order numbers and dates,
 *   - billing address and delivery address (or the collection point),
 *   - payment method + paid/unpaid state,
 *   - delivery/collection method with date and time window,
 *   - every ordered line with its full meal description and add-ons, plus
 *     anything the customer typed against that line (product input fields),
 *   - the customer's order notes from the checkout, and the value of any
 *     additional checkout field another plugin or snippet registered,
 *   - totals, including the delivery surcharge and any discounts or refunds.
 *
 * Design constraints:
 *   - MONOCHROME. Every colour is #000 on #fff or inverted. Secondary text is
 *     italic rather than grey so it stays crisp on a mono laser printer and
 *     photocopies cleanly.
 *   - No VAT/tax presentation: the business is not VAT registered, so there is
 *     no VAT number and no net/VAT/gross breakdown. A tax row appears only in
 *     the defensive case where WooCommerce actually recorded tax on the order,
 *     so the totals can never fail to add up.
 */
final class InvoicePrinter {

	/**
	 * Render images at 150 dpi rather than Dompdf's 96 dpi screen default so the
	 * brand logo is sampled close to laser-printer resolution instead of
	 * printing soft. Layout is unaffected: every dimension below is in mm or pt.
	 */
	private const RENDER_DPI = 150;

	/**
	 * Stream an invoices PDF to the browser and exit.
	 *
	 * Always sends no-cache headers with a unique filename so the browser can
	 * never re-display a stale PDF from a previous click — the bulk-action URL
	 * is identical every time and would otherwise hit the disk cache.
	 *
	 * @param int[] $order_ids Order IDs to invoice, in the order given.
	 */
	public static function stream( array $order_ids ): void {
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install in the plugin directory.', 'fastnutrition-mealprep' ) );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$orders = self::load_orders( $order_ids );
		$html   = self::build_html( $orders );

		$dompdf = new \Dompdf\Dompdf(
			[
				// Remote fetching stays OFF: the brand logo is inlined as a base64
				// data URI and invoices carry no other external resources. Leaving
				// it on would let any unescaped order field that reached the markup
				// (e.g. an <img> in a customer-supplied value) make the server fetch
				// an attacker URL when staff print invoices — an SSRF vector — for
				// zero functional benefit.
				'isRemoteEnabled'      => false,
				'isPhpEnabled'         => false,
				'defaultMediaType'     => 'print',
				'isHtml5ParserEnabled' => true,
				'dpi'                  => self::RENDER_DPI,
				// Keep the chroot scoped to the WP install for any local file Dompdf
				// may need to resolve, never the whole filesystem.
				'chroot'               => [ ABSPATH, WP_CONTENT_DIR ],
			]
		);
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$pdf = (string) $dompdf->output();

		// A single-order print gets a filename staff can file straight away;
		// a batch gets a generic one. The random suffix defeats both the URL
		// cache and any "open recent PDF" reuse in the viewer.
		$stem = 1 === count( $orders )
			? 'invoice-' . (int) $orders[0]->get_id()
			: 'invoices-' . count( $orders );
		$filename = $stem . '-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		echo $pdf;
		exit;
	}

	/**
	 * Render the invoice HTML directly to the browser (no PDF rasterisation).
	 *
	 * Use this for design iteration. Same CSS as the PDF, but the response is
	 * normal HTML, so it bypasses every PDF viewer cache and can be inspected
	 * in browser DevTools. Refresh the tab to see the latest design. Reached
	 * from the "Preview invoice" bulk action on the Orders list.
	 *
	 * @param int[] $order_ids
	 */
	public static function stream_html( array $order_ids ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$html = self::build_html( self::load_orders( $order_ids ) );

		// Screen-mode stylesheet so each .invoice looks like a real A4 sheet on
		// a grey desk. Print-mode CSS is untouched — the @page rules already in
		// <style> handle the PDF case.
		$screen_css = <<<'CSS'
<style>
@media screen {
	html { background: #ddd; padding: 24px; margin: 0; }
	body { background: transparent; margin: 0; padding: 0; }
	.preview-banner {
		width: 210mm;
		box-sizing: border-box;
		margin: 0 auto 16px;
		padding: 10px 14px;
		background: #fff8e5;
		border: 1px dashed #dba617;
		font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif;
		font-size: 13px;
		line-height: 1.45;
	}
	.preview-banner code { background: #fff; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
	.invoice {
		width: 210mm;
		min-height: 297mm;
		box-sizing: border-box;
		padding: 12mm;
		background: #fff;
		margin: 0 auto 16px;
		box-shadow: 0 2px 12px rgba(0,0,0,0.25);
	}
}
@media print {
	.preview-banner { display: none; }
}
</style>
CSS;

		$banner_msg = sprintf(
			/* translators: 1: plugin version, 2: render timestamp */
			esc_html__( 'Rendered from plugin %1$s at %2$s. Refresh this tab (⌘R / Ctrl+R) to re-render with the latest code.', 'fastnutrition-mealprep' ),
			'<code>v' . esc_html( defined( 'FN_MEALPREP_VERSION' ) ? FN_MEALPREP_VERSION : '?' ) . '</code>',
			'<code>' . esc_html( wp_date( 'H:i:s' ) ) . '</code>'
		);
		$banner = '<div class="preview-banner"><strong>' . esc_html__( 'Invoice preview', 'fastnutrition-mealprep' ) . '</strong> — ' . $banner_msg . '</div>';

		$html = str_replace( '</head>', $screen_css . '</head>', $html );
		$html = preg_replace( '/<body([^>]*)>/', '<body$1>' . $banner, $html, 1 );

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $html;
		exit;
	}

	/**
	 * @param int[] $order_ids
	 * @return \WC_Order[]
	 */
	private static function load_orders( array $order_ids ): array {
		$orders = [];
		foreach ( $order_ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * @param \WC_Order[] $orders
	 */
	private static function build_html( array $orders ): string {
		$brand                  = SettingsPage::brand_info();
		$brand['logo_data_uri'] = LabelPrinter::logo_data_uri( $brand );
		$brand['company']       = self::company_name();
		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Invoices', 'fastnutrition-mealprep' ); ?></title>
<style>
	/* A4 invoice design notes:
	   - Monochrome only. Every colour is #000 on #fff or inverted; secondary
	     text is italic rather than grey so nothing depends on a printer
	     rendering a mid-tone, and the sheet photocopies cleanly.
	   - 12 mm page margin, giving a 186 mm content column. Every box below
	     declares an EXPLICIT mm width — Dompdf does not reliably constrain
	     children to a padding-edge, so percentage/auto widths drift past the
	     right margin on long content.

	   - !! Dompdf IGNORES `box-sizing: border-box`. Every width below is
	     therefore a CONTENT width: Dompdf adds horizontal padding and borders
	     on top of it. All the numbers are pre-subtracted so the box actually
	     RENDERS at 186 mm (or 76 mm for the totals). Measured, not assumed —
	     declaring the pretty number instead pushed the items table 16 mm past
	     the right margin. If you change a padding here, change the width to
	     match:
	         items row  10 + 100 + 26 + 34  (+ 4 mm padding each) = 186 mm
	         totals     40 + 28             (+ 4 mm padding each) =  76 mm
	         boxed divs 186 − (2 × padding) − (2 × border)

	   - Column layout is done with tables, not flex/grid, which Dompdf ignores.
	   - Emphasis is asymmetric on purpose: PAID is a quiet ruled line, AMOUNT
	     DUE is a full-width inverted bar, so an unpaid invoice is unmissable. */
	/* The page margin is carried by BODY, not by @page. Dompdf resolves the
	   @page margin through the root box, so the `html, body { margin: 0 }`
	   reset the label stylesheet uses (correct there — a 100mm label wants a
	   zero margin) silently drops it and prints the invoice hard against the
	   paper edge, inside the printer's unprintable region. Declaring it on
	   body instead is honoured on every page, including continuations.
	   210 − (2 × 12) = the 186mm content column every width below assumes. */
	@page { size: A4 portrait; margin: 0; }
	html { margin: 0; padding: 0; }
	body { margin: 12mm; padding: 0; font-family: DejaVu Sans, sans-serif; color: #000; font-size: 9pt; line-height: 11.5pt; }
	table { border-collapse: collapse; }
	.muted { font-style: italic; }

	/* Each invoice is its own page. The break goes BEFORE every invoice after
	   the first rather than after each — page-break-after leaves Dompdf
	   emitting a trailing blank page at the end of the document. */
	.invoice + .invoice { page-break-before: always; }

	/* ---- Header: logo + company block on the left, INVOICE title on the right ---- */
	.inv-head { width: 186mm; margin-bottom: 3mm; }
	.inv-head td { vertical-align: top; padding: 0; }
	.inv-head-brand { width: 106mm; }
	.inv-head-meta { width: 80mm; text-align: right; }
	/* Logo and company details sit SIDE BY SIDE, not stacked. Stacking made the
	   header block as tall as both together (~31mm); side by side it is only as
	   tall as the taller of the two (~22mm), which is worth about two extra item
	   rows on the sheet. No company detail is dropped to get there. */
	.inv-brand-split { width: 106mm; }
	.inv-brand-split td { padding: 0; vertical-align: top; }
	.inv-brand-logo-cell { width: 42mm; padding-right: 4mm !important; }
	.inv-brand-text-cell { width: 60mm; }
	/* display:block — as an inline image it sits on a line box and drags the
	   font's descender space along with it, costing ~3mm of header height. */
	.inv-logo { display: block; max-width: 42mm; max-height: 22mm; }
	.inv-company { font-size: 11pt; font-weight: bold; margin-bottom: 0.8mm; }
	/* One <div> per line rather than <br> — Dompdf turns the whitespace
	   between a <br> and the next text node into an extra blank line box,
	   which quietly added ~9 mm to the header. */
	.inv-company-lines { font-size: 8pt; line-height: 10.5pt; }
	.inv-title { font-size: 20pt; font-weight: bold; letter-spacing: 0.8mm; line-height: 1; margin-bottom: 2mm; }
	/* Reference table sits flush right under the title. Right column is the
	   value, left the label — both right-aligned so the numbers line up. */
	.inv-refs { width: 80mm; font-size: 8pt; }
	.inv-refs td { padding: 0.3mm 0; vertical-align: top; }
	.inv-refs-label { text-align: right; padding-right: 3mm !important; width: 34mm; }
	.inv-refs-value { text-align: right; width: 43mm; font-weight: bold; }

	/* Full-width rule under the header. */
	.inv-rule { width: 186mm; height: 0; border-top: 1.2pt solid #000; margin-bottom: 3mm; }

	/* ---- Address + fulfilment panels ---- */
	.inv-panels { width: 186mm; margin-bottom: 3mm; }
	.inv-panels td { vertical-align: top; padding: 0; }
	.inv-panel-l { width: 91mm; padding-right: 4mm !important; }
	.inv-panel-r { width: 91mm; }
	.inv-panel-title {
		font-size: 7.5pt;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.4mm;
		border-bottom: 0.6pt solid #000;
		padding-bottom: 0.6mm;
		margin-bottom: 1.2mm;
	}
	.inv-panel-body { font-size: 8.5pt; line-height: 11pt; }
	.inv-panel-name { font-weight: bold; }

	/* Fulfilment strip — the delivery/collection method, date and window.
	   Boxed so the driver/counter detail is findable at a glance.
	   179.4 = 186 − (2 × 3 mm padding) − (2 × 0.8 pt border). */
	.inv-fulfilment {
		width: 179.4mm;
		border: 0.8pt solid #000;
		padding: 1.5mm 3mm;
		margin-bottom: 3mm;
		font-size: 9pt;
	}
	.inv-fulfilment-method { font-weight: bold; text-transform: uppercase; letter-spacing: 0.3mm; }

	/* ---- Items table ---- */
	.inv-items { width: 186mm; margin-bottom: 3mm; }
	/* Continuation caption: repeats with the header on page 2+, so a spilled
	   page still identifies which invoice it belongs to. */
	.inv-items-caption td {
		font-size: 8pt;
		font-style: italic;
		border: 0;
		border-bottom: 0.6pt solid #000;
		padding: 0 0 1mm;
	}
	.inv-items thead th {
		font-size: 7.5pt;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.3mm;
		text-align: left;
		border-bottom: 1.2pt solid #000;
		padding: 1.2mm 2mm;
	}
	.inv-items tbody td {
		padding: 1.2mm 2mm;
		border-bottom: 0.4pt solid #000;
		vertical-align: top;
	}
	/* Content widths; +4mm padding each renders 12 + 116 + 28 + 30 = 186mm.
	   The money columns are kept just wide enough for a five-figure amount so
	   the description gets the rest — at 112mm a typical four-part meal
	   description lands on ONE line instead of wrapping onto two. */
	.col-qty  { width: 8mm; text-align: center; }
	.col-desc { width: 112mm; }
	.col-unit { width: 24mm; text-align: right; }
	.col-amt  { width: 26mm; text-align: right; }
	/* Description and add-ons share ONE line so a typical order stays on a
	   single sheet. Add-ons are italic and un-bolded so they read as secondary
	   detail inside the bold line rather than as part of the meal itself. */
	.inv-item-name { font-weight: bold; font-size: 8.5pt; line-height: 11pt; word-wrap: break-word; overflow-wrap: break-word; }
	.inv-item-addons { font-style: italic; font-weight: normal; font-size: 8pt; }
	/* Text the customer typed against the line (product input fields, gift
	   messages): one italic line each under the description, never bold, so
	   the meal stays the headline. */
	.inv-item-fields { font-style: italic; font-weight: normal; font-size: 8pt; line-height: 10.5pt; word-wrap: break-word; overflow-wrap: break-word; }
	/* Keep a line's name and description together across a page break. */
	.inv-items tbody tr { page-break-inside: avoid; }

	/* ---- Summary row: customer note on the left, totals on the right ----
	   The totals block only needs 76mm, so the 110mm beside it would otherwise
	   be dead space. Putting the order note there instead of below buys ~16mm
	   of vertical room — enough to keep a typical order on one sheet. */
	.inv-totals-wrap { width: 186mm; }
	.inv-totals-wrap td { padding: 0; vertical-align: top; }
	.inv-totals-spacer { width: 106mm; padding-right: 4mm !important; }
	.inv-totals-cell { width: 76mm; }
	.inv-totals { width: 76mm; font-size: 9pt; }
	.inv-totals td { padding: 1mm 2mm; }
	/* Content widths; +4mm padding each renders 44 + 32 = 76mm. */
	.inv-totals-label { text-align: right; width: 40mm; }
	.inv-totals-value { text-align: right; width: 28mm; }
	.inv-totals-sep td { border-top: 0.6pt solid #000; }
	/* Grand total inverted so the eye lands on it immediately. */
	.inv-total-row td {
		background: #000;
		color: #fff;
		font-weight: bold;
		font-size: 11pt;
		padding: 1.6mm 2mm;
	}

	/* Payment verdict + brand foot travel together. Left to break freely, an
	   invoice that overflows by a millimetre puts a single orphaned footer line
	   on page 2; as a unit they read as a deliberate closing block. */
	.inv-close { page-break-inside: avoid; }

	/* ---- Payment status ---- */
	.inv-payment { width: 186mm; margin-top: 3mm; text-align: center; page-break-inside: avoid; }
	.inv-payment--paid {
		border-top: 0.8pt solid #000;
		border-bottom: 0.8pt solid #000;
		padding: 1.5mm 0;
		font-size: 9.5pt;
	}
	.inv-payment--paid .inv-payment-badge { font-weight: bold; letter-spacing: 0.8mm; }
	.inv-payment--paid .inv-payment-note { margin-left: 3mm; }
	.inv-payment--due { background: #000; color: #fff; padding: 2.5mm 0; }
	.inv-payment--due .inv-payment-badge { display: block; font-size: 13pt; font-weight: bold; letter-spacing: 1.2mm; }
	.inv-payment--due .inv-payment-note { display: block; font-size: 8.5pt; margin-top: 1mm; }

	/* ---- Customer note ----
	   Sits in the 106mm left cell of the summary row.
	   99.6 = 106 − (2 × 3 mm padding) − (2 × 0.6 pt border). */
	.inv-note {
		width: 99.6mm;
		border: 0.6pt solid #000;
		padding: 1.5mm 3mm;
		font-size: 8.5pt;
	}
	.inv-note-title { font-weight: bold; text-transform: uppercase; font-size: 7.5pt; letter-spacing: 0.3mm; margin-bottom: 0.6mm; }
	/* A second source (an extra checkout field) gets its own heading inside
	   the same box, with a little air above it. */
	.inv-note-title--next { margin-top: 1.5mm; }
	.inv-note-text { line-height: 11pt; word-wrap: break-word; overflow-wrap: break-word; }
	/* Long notes: full width under the summary row instead of beside the
	   totals, so a ten-line note wraps less and can flow onto page 2.
	   179.5 = 186 − (2 × 3 mm padding) − (2 × 0.6 pt border). */
	.inv-note--wide { width: 179.5mm; margin-top: 3mm; }

	/* ---- Foot ----
	   Kept in normal flow rather than pinned to the page bottom: a fixed-position
	   footer in Dompdf is document-wide, so it could not carry per-invoice text,
	   and an absolutely positioned one needs a fixed-height parent, which an
	   invoice that may spill to a second page cannot have. */
	.inv-foot {
		width: 186mm;
		border-top: 0.8pt solid #000;
		padding-top: 1.2mm;
		margin-top: 3mm;
		font-size: 8pt;
		line-height: 10.5pt;
		text-align: center;
		page-break-inside: avoid;
	}
	.inv-foot-thanks { font-weight: bold; margin-bottom: 0.8mm; }
	.inv-foot-line { line-height: 10.5pt; }
</style>
</head>
<body>
		<?php
		if ( empty( $orders ) ) {
			echo '<div class="invoice"><p>' . esc_html__( 'No orders were selected, or none of them could be loaded.', 'fastnutrition-mealprep' ) . '</p></div>';
		}
		foreach ( $orders as $order ) {
			self::render_invoice( $order, $brand );
		}
		?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_invoice( \WC_Order $order, array $brand ): void {
		$summary       = FulfilmentDisplay::summary( $order );
		$is_collection = 'collection' === ( $summary['type'] ?? '' );
		$payment       = LabelPrinter::payment_status( $order );
		$is_paid       = 'paid' === $payment['state'];
		$notes         = self::order_notes( $order );
		?>
		<div class="invoice">
			<?php self::render_head( $order, $brand ); ?>
			<div class="inv-rule"></div>
			<?php self::render_addresses( $order, $is_collection ); ?>
			<?php self::render_fulfilment( $summary ); ?>
			<?php self::render_items( $order ); ?>
			<?php self::render_summary( $order, $notes ); ?>

			<div class="inv-close">
				<div class="inv-payment inv-payment--<?php echo $is_paid ? 'paid' : 'due'; ?>">
					<span class="inv-payment-badge">
						<?php echo $is_paid ? esc_html__( 'PAID IN FULL', 'fastnutrition-mealprep' ) : esc_html__( 'AMOUNT DUE', 'fastnutrition-mealprep' ); ?>
					</span>
					<span class="inv-payment-note">
						<?php
						if ( $is_paid ) {
							echo '' !== $payment['title']
								/* translators: %s: payment method name, e.g. "Credit Card (Stripe)" */
								? esc_html( sprintf( __( 'Paid via %s — no payment is due.', 'fastnutrition-mealprep' ), $payment['title'] ) )
								: esc_html__( 'No payment is due.', 'fastnutrition-mealprep' );
						} else {
							$due = self::money( (float) $order->get_total() - (float) $order->get_total_refunded(), $order );
							echo '' !== $payment['title']
								/* translators: 1: amount due, 2: payment method name */
								? esc_html( sprintf( __( '%1$s payable by %2$s', 'fastnutrition-mealprep' ), $due, $payment['title'] ) )
								/* translators: %s: amount due */
								: esc_html( sprintf( __( '%s payable', 'fastnutrition-mealprep' ), $due ) );
						}
						?>
					</span>
				</div>
				<?php self::render_foot( $brand ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_head( \WC_Order $order, array $brand ): void {
		$logo_src   = (string) ( $brand['logo_data_uri'] ?? '' );
		$company    = (string) ( $brand['company'] ?? '' );
		$address    = trim( (string) ( $brand['address'] ?? '' ) );
		$web        = trim( (string) ( $brand['web'] ?? '' ) );
		$email      = trim( (string) ( $brand['email'] ?? '' ) );
		$phone      = trim( (string) ( $brand['phone'] ?? '' ) );
		$created    = $order->get_date_created();
		// Invoice date is the order date, not the print date, so re-printing an
		// invoice months later reproduces byte-identical paperwork.
		$date_label = $created ? wp_date( 'j F Y', $created->getTimestamp() ) : '';
		?>
		<table class="inv-head">
			<tr>
				<td class="inv-head-brand">
					<table class="inv-brand-split">
						<tr>
							<td class="inv-brand-logo-cell">
								<?php if ( '' !== $logo_src ) : ?>
									<img class="inv-logo" src="<?php echo esc_attr( $logo_src ); ?>" alt="" />
								<?php endif; ?>
							</td>
							<td class="inv-brand-text-cell">
								<?php if ( '' !== $company ) : ?>
									<div class="inv-company"><?php echo esc_html( $company ); ?></div>
								<?php endif; ?>
								<div class="inv-company-lines">
									<?php
									// Emitted as one <div> per line, and never with
									// <br>: Dompdf turns the source whitespace around
									// a <br> into extra empty line boxes, which added
									// ~9mm to the header.
									//
									// The multi-line brand address is flattened onto a
									// single line, but phone and email each keep their
									// own — joined they overflow the 60mm brand column
									// and wrap mid-way ("☎ 07712 345 678 ✉ /
									// hello@example.co.uk"), which reads as broken. The
									// foot has the full 186mm and joins them there.
									$lines = [];
									if ( '' !== $address ) {
										$lines[] = implode( ', ', array_filter( array_map( 'trim', preg_split( '/\R/', $address ) ?: [] ) ) );
									}
									if ( '' !== $phone ) {
										$lines[] = '☎ ' . $phone;
									}
									if ( '' !== $email ) {
										$lines[] = '✉ ' . $email;
									}
									if ( '' !== $web ) {
										$lines[] = $web;
									}
									foreach ( $lines as $line ) {
										echo '<div>' . esc_html( $line ) . '</div>';
									}
									?>
								</div>
							</td>
						</tr>
					</table>
				</td>
				<td class="inv-head-meta">
					<div class="inv-title"><?php esc_html_e( 'INVOICE', 'fastnutrition-mealprep' ); ?></div>
					<table class="inv-refs">
						<tr>
							<td class="inv-refs-label"><?php esc_html_e( 'Invoice number', 'fastnutrition-mealprep' ); ?></td>
							<td class="inv-refs-value">#<?php echo esc_html( $order->get_order_number() ); ?></td>
						</tr>
						<?php if ( '' !== $date_label ) : ?>
							<tr>
								<td class="inv-refs-label"><?php esc_html_e( 'Invoice date', 'fastnutrition-mealprep' ); ?></td>
								<td class="inv-refs-value"><?php echo esc_html( $date_label ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<td class="inv-refs-label"><?php esc_html_e( 'Payment method', 'fastnutrition-mealprep' ); ?></td>
							<td class="inv-refs-value">
								<?php
								$method = trim( (string) $order->get_payment_method_title() );
								echo '' !== $method ? esc_html( $method ) : '<span class="muted">' . esc_html__( 'Not recorded', 'fastnutrition-mealprep' ) . '</span>';
								?>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_addresses( \WC_Order $order, bool $is_collection ): void {
		$billing  = $order->get_formatted_billing_address();
		$shipping = $order->get_formatted_shipping_address();
		$phone    = trim( (string) $order->get_billing_phone() );
		$email    = trim( (string) $order->get_billing_email() );
		?>
		<table class="inv-panels">
			<tr>
				<td class="inv-panel-l">
					<div class="inv-panel-title"><?php esc_html_e( 'Invoice to', 'fastnutrition-mealprep' ); ?></div>
					<div class="inv-panel-body">
						<div class="inv-panel-name"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></div>
						<?php
						self::render_address_lines( $billing );
						if ( '' !== $phone ) {
							echo '<div>☎ ' . esc_html( $phone ) . '</div>';
						}
						if ( '' !== $email ) {
							echo '<div>✉ ' . esc_html( $email ) . '</div>';
						}
						?>
					</div>
				</td>
				<td class="inv-panel-r">
					<?php if ( $is_collection ) : ?>
						<div class="inv-panel-title"><?php esc_html_e( 'Collect from', 'fastnutrition-mealprep' ); ?></div>
						<div class="inv-panel-body">
							<div class="inv-panel-name"><?php echo esc_html( FulfilmentDisplay::collection_business() ); ?></div>
							<?php echo esc_html( FulfilmentDisplay::collection_address() ); ?>
						</div>
					<?php else : ?>
						<div class="inv-panel-title"><?php esc_html_e( 'Deliver to', 'fastnutrition-mealprep' ); ?></div>
						<div class="inv-panel-body">
							<?php
							if ( $shipping || $billing ) {
								self::render_address_lines( $shipping ?: $billing );
							} else {
								echo '<span class="muted">' . esc_html__( 'No delivery address recorded.', 'fastnutrition-mealprep' ) . '</span>';
							}
							?>
						</div>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Emit a WooCommerce formatted address as one <div> per line.
	 *
	 * get_formatted_*_address() joins lines with <br/>. Echoing that string
	 * straight into an indented template leaves Dompdf treating the surrounding
	 * source whitespace as extra empty line boxes, which added roughly 10mm to
	 * the address panels. Splitting on the <br/> and emitting explicit blocks
	 * gives exactly the lines the address actually has.
	 */
	private static function render_address_lines( string $formatted ): void {
		$lines = preg_split( '#<br\s*/?>#i', $formatted ) ?: [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				echo '<div>' . wp_kses_post( $line ) . '</div>';
			}
		}
	}

	/**
	 * @param array<string,string> $summary Result of FulfilmentDisplay::summary().
	 */
	private static function render_fulfilment( array $summary ): void {
		if ( empty( $summary ) ) {
			return;
		}
		$method = (string) ( $summary['method_label'] ?? '' );
		$value  = (string) ( $summary['customer_value'] ?? '' );
		if ( '' === $method && '' === $value ) {
			return;
		}
		?>
		<div class="inv-fulfilment">
			<span class="inv-fulfilment-method"><?php echo esc_html( $method ); ?></span>
			<?php if ( '' !== $value ) : ?>
				&nbsp;·&nbsp; <?php echo esc_html( $value ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_items( \WC_Order $order ): void {
		?>
		<table class="inv-items">
			<thead>
				<tr class="inv-items-caption">
					<td colspan="4">
						<?php
						printf(
							/* translators: 1: invoice number, 2: customer name */
							esc_html__( 'Invoice #%1$s — %2$s', 'fastnutrition-mealprep' ),
							esc_html( $order->get_order_number() ),
							esc_html( $order->get_formatted_billing_full_name() )
						);
						?>
					</td>
				</tr>
				<tr>
					<th class="col-qty"><?php esc_html_e( 'Qty', 'fastnutrition-mealprep' ); ?></th>
					<th class="col-desc"><?php esc_html_e( 'Description', 'fastnutrition-mealprep' ); ?></th>
					<th class="col-unit"><?php esc_html_e( 'Unit price', 'fastnutrition-mealprep' ); ?></th>
					<th class="col-amt"><?php esc_html_e( 'Amount', 'fastnutrition-mealprep' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$items = $order->get_items();
			if ( empty( $items ) ) {
				echo '<tr><td colspan="4"><span class="muted">' . esc_html__( 'This order has no items.', 'fastnutrition-mealprep' ) . '</span></td></tr>';
			}
			foreach ( $items as $item ) {
				$qty = (int) $item->get_quantity();
				// Line amount is the pre-discount subtotal so the subtotal row is
				// the honest sum of the lines and any discount reduces it
				// explicitly, rather than being silently baked into the prices.
				$line_total = (float) $item->get_subtotal();
				$unit       = $qty > 0 ? $line_total / $qty : $line_total;
				?>
				<tr>
					<td class="col-qty"><?php echo (int) $qty; ?></td>
					<td class="col-desc">
						<?php
						// One line per item, not two. The meal description is the
						// headline — same text the meal label on the box carries,
						// so the invoice and the label always agree — and the
						// product name ("Build Your Own Meal") is only used when
						// there is no description to show instead. Stacking both
						// cost ~3.5mm per line and pushed a 6-meal order onto a
						// second sheet for no added information.
						//
						// Built as one string rather than indented markup: the
						// whitespace around a nested <span> renders as a visible
						// double space in the PDF.
						$desc    = self::item_description( $item );
						$addons  = self::item_addons( $item );
						$printed = ( '' !== $desc ? $desc : (string) $item->get_name() ) . ' ' . implode( ', ', $addons );
						$line    = esc_html( '' !== $desc ? $desc : $item->get_name() );
						if ( ! empty( $addons ) ) {
							$line .= ' <span class="inv-item-addons">' . sprintf(
								/* translators: %s: comma-separated add-on names */
								esc_html__( '· Add-ons: %s', 'fastnutrition-mealprep' ),
								esc_html( implode( ', ', $addons ) )
							) . '</span>';
						}
						echo '<div class="inv-item-name">' . $line . '</div>';
						// Anything the customer typed against this line (a product
						// input field, a gift message) goes under it, one line each.
						foreach ( self::item_fields( $item, $printed ) as $field ) {
							echo '<div class="inv-item-fields">' . esc_html( $field ) . '</div>';
						}
						?>
					</td>
					<td class="col-unit"><?php echo esc_html( self::money( $unit, $order ) ); ?></td>
					<td class="col-amt"><?php echo esc_html( self::money( $line_total, $order ) ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Human-readable meal composition for an order line — the same description
	 * the meal labels print, so the invoice and the label on the box agree.
	 */
	private static function item_description( \WC_Order_Item $item ): string {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return '';
		}
		$selection = $item->get_meta( '_fn_selection', true );
		if ( ! is_array( $selection ) ) {
			// Fall back to the cart-attached selection key for older items.
			$selection = $item->get_meta( Selections::CART_KEY, true );
		}
		if ( ! is_array( $selection ) ) {
			return '';
		}
		$desc = PrepDashboard::describe_selection( $selection );
		// describe_selection() returns the single ingredient's own title for
		// standalone/sweet lines, which is usually already the product name —
		// don't repeat it directly under itself.
		return 0 === strcasecmp( trim( $desc ), trim( $item->get_name() ) ) ? '' : $desc;
	}

	/**
	 * Add-on labels for an order line, with a "× N" suffix where the same add-on
	 * was chosen more than once.
	 *
	 * @return string[]
	 */
	private static function item_addons( \WC_Order_Item $item ): array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return [];
		}
		$selection = $item->get_meta( '_fn_selection', true );
		if ( ! is_array( $selection ) ) {
			$selection = $item->get_meta( Selections::CART_KEY, true );
		}
		if ( ! is_array( $selection ) || empty( $selection['addons'] ) ) {
			return [];
		}
		$out = [];
		foreach ( Selection::addon_counts( $selection ) as $label => $count ) {
			$out[] = $count > 1 ? $label . ' × ' . (int) $count : (string) $label;
		}
		return $out;
	}

	/**
	 * Anything the customer typed against a single line while ordering — a
	 * product input field, a gift message — as "Label: value" strings.
	 *
	 * This is the same visible line-item meta WooCommerce prints under the line
	 * in the admin order screen and in the order emails, minus what the invoice
	 * already says: the plugin's own composition pairs ("Protein: Chicken"),
	 * "Add-ons" and "Macros" rows that OrderItemMeta writes are summarised in
	 * the description line, so they are skipped by key, and any other value the
	 * printed line already contains (a variation attribute that IS the product,
	 * say) is skipped too.
	 *
	 * @param string $printed The description and add-ons text printed above.
	 * @return string[]
	 */
	private static function item_fields( \WC_Order_Item $item, string $printed ): array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return [];
		}
		$own       = [ __( 'Add-ons', 'fastnutrition-mealprep' ), __( 'Macros', 'fastnutrition-mealprep' ) ];
		$selection = $item->get_meta( '_fn_selection', true );
		if ( ! is_array( $selection ) ) {
			$selection = $item->get_meta( Selections::CART_KEY, true );
		}
		if ( is_array( $selection ) ) {
			foreach ( Selection::composition_pairs( $selection ) as $pair ) {
				$own[] = (string) ( $pair['key'] ?? '' );
			}
		}
		$own = array_map( 'strtolower', $own );

		$out = [];
		foreach ( $item->get_formatted_meta_data() as $meta ) {
			$label = trim( wp_strip_all_tags( (string) ( $meta->display_key ?? '' ) ) );
			// display_value arrives wrapped in <p> by wpautop(); flatten to text.
			$value = trim( html_entity_decode( wp_strip_all_tags( (string) ( $meta->display_value ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $value || in_array( strtolower( $label ), $own, true ) ) {
				continue;
			}
			if ( false !== stripos( $printed, $value ) ) {
				continue;
			}
			$out[] = '' !== $label ? $label . ': ' . $value : $value;
		}
		/**
		 * Filter the per-line customer fields printed under an invoice line.
		 *
		 * @param string[]       $out  "Label: value" strings, in print order.
		 * @param \WC_Order_Item $item The order line.
		 */
		$out = apply_filters( 'fn_mealprep_invoice_item_fields', $out, $item );
		$out = is_array( $out ) ? $out : [];

		$clean = [];
		foreach ( $out as $field ) {
			if ( is_scalar( $field ) && '' !== trim( (string) $field ) ) {
				$clean[] = trim( (string) $field );
			}
		}
		return $clean;
	}

	/**
	 * Every note the customer typed while placing the order, as label/text
	 * pairs in print order.
	 *
	 * Sources:
	 *   1. The checkout's "Add a note to your order" box. WooCommerce stores it
	 *      as the order's customer note — the text the admin order screen shows
	 *      under "Customer provided note".
	 *   2. Any additional checkout field in the order or contact location
	 *      registered through WooCommerce Blocks (a "Delivery instructions" box
	 *      added by another plugin or a snippet, for instance).
	 *
	 * @return array<int, array{label: string, text: string}>
	 */
	private static function order_notes( \WC_Order $order ): array {
		$notes = [];

		$customer_note = trim( (string) $order->get_customer_note() );
		if ( '' !== $customer_note ) {
			$notes[] = [
				'label' => __( 'Order notes', 'fastnutrition-mealprep' ),
				'text'  => $customer_note,
			];
		}

		foreach ( self::additional_checkout_fields( $order ) as $field ) {
			$notes[] = $field;
		}

		/**
		 * Filter the notes printed on an invoice, in the box beside the totals.
		 *
		 * @param array<int, array{label: string, text: string}> $notes
		 * @param \WC_Order                                       $order
		 */
		$notes = apply_filters( 'fn_mealprep_invoice_notes', $notes, $order );
		$notes = is_array( $notes ) ? $notes : [];

		$clean = [];
		foreach ( $notes as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$text = trim( (string) ( $entry['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$clean[] = [
				'label' => trim( (string) ( $entry['label'] ?? '' ) ),
				'text'  => $text,
			];
		}
		return $clean;
	}

	/**
	 * Values of additional checkout fields (WooCommerce Blocks) saved on the
	 * order, as label/text pairs.
	 *
	 * Read straight from the order meta so it works whichever WooCommerce
	 * version wrote the order; the field definitions, when the service is
	 * available, only supply the label and option names. Address-location
	 * fields live under other prefixes and belong to the address panels, not
	 * here. Checkbox fields are skipped — a ticked "keep me posted" box is not a
	 * note.
	 *
	 * @return array<int, array{label: string, text: string}>
	 */
	private static function additional_checkout_fields( \WC_Order $order ): array {
		// Order- and contact-location fields are persisted under these prefixes
		// ("_wc_additional/" in the first WooCommerce releases, "_wc_other/" since).
		$prefixes    = [ '_wc_other/', '_wc_additional/' ];
		$definitions = self::checkout_field_definitions();
		$out         = [];

		foreach ( $order->get_meta_data() as $meta ) {
			$key    = (string) $meta->key;
			$prefix = '';
			foreach ( $prefixes as $candidate ) {
				if ( str_starts_with( $key, $candidate ) ) {
					$prefix = $candidate;
					break;
				}
			}
			if ( '' === $prefix || ! is_scalar( $meta->value ) ) {
				continue;
			}
			$field_id = substr( $key, strlen( $prefix ) );
			$field    = isset( $definitions[ $field_id ] ) && is_array( $definitions[ $field_id ] ) ? $definitions[ $field_id ] : [];
			$type     = (string) ( $field['type'] ?? '' );
			if ( 'checkbox' === $type ) {
				continue;
			}
			$value = trim( (string) $meta->value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'select' === $type && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					if ( is_array( $option ) && isset( $option['value'] ) && (string) $option['value'] === $value ) {
						$value = (string) ( $option['label'] ?? $value );
						break;
					}
				}
			}
			$label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
			if ( '' === $label ) {
				// No definition any more (the registering plugin is gone):
				// humanise the id's last segment, "acme/delivery-instructions"
				// → "Delivery instructions".
				$segments = explode( '/', $field_id );
				$label    = ucfirst( str_replace( [ '-', '_' ], ' ', (string) end( $segments ) ) );
			}
			$out[] = [
				'label' => $label,
				'text'  => $value,
			];
		}
		return $out;
	}

	/**
	 * Additional checkout field definitions (field id => definition) from the
	 * WooCommerce Blocks CheckoutFields service, or an empty array when the
	 * service is not available on this WooCommerce version.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function checkout_field_definitions(): array {
		$class = \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class;
		if ( ! class_exists( $class ) ) {
			return [];
		}
		try {
			$service = null;
			if ( class_exists( \Automattic\WooCommerce\Blocks\Package::class ) ) {
				$service = \Automattic\WooCommerce\Blocks\Package::container()->get( $class );
			} elseif ( function_exists( 'wc_get_container' ) ) {
				$service = wc_get_container()->get( $class );
			}
			if ( is_object( $service ) && method_exists( $service, 'get_additional_fields' ) ) {
				$fields = $service->get_additional_fields();
				return is_array( $fields ) ? $fields : [];
			}
		} catch ( \Throwable $e ) {
			// Fall through: labels are derived from the field ids instead.
			unset( $e );
		}
		return [];
	}

	/**
	 * The summary row beneath the items: the customer's order notes on the
	 * left, the money totals on the right.
	 *
	 * @param array<int, array{label: string, text: string}> $notes From order_notes().
	 */
	private static function render_summary( \WC_Order $order, array $notes ): void {
		$rows = [];

		$rows[] = [ __( 'Subtotal', 'fastnutrition-mealprep' ), self::money( (float) $order->get_subtotal(), $order ), false ];

		$discount = (float) $order->get_discount_total();
		if ( $discount > 0 ) {
			$rows[] = [ __( 'Discount', 'fastnutrition-mealprep' ), '−' . self::money( $discount, $order ), false ];
		}

		// Fees carry the delivery surcharge (Cart\Surcharge adds it via
		// WC_Cart::add_fee()), so each one is named with its own label.
		foreach ( $order->get_fees() as $fee ) {
			$rows[] = [ (string) $fee->get_name(), self::money( (float) $fee->get_total(), $order ), false ];
		}

		$shipping = (float) $order->get_shipping_total();
		if ( abs( $shipping ) > 0.0001 ) {
			$label  = trim( (string) $order->get_shipping_method() );
			$rows[] = [ '' !== $label ? $label : __( 'Delivery', 'fastnutrition-mealprep' ), self::money( $shipping, $order ), false ];
		}

		// The business is not VAT registered, so there is normally no tax to
		// show. This row exists only so that, if WooCommerce ever did record tax
		// on an order, the printed lines still add up to the printed total.
		$tax = (float) $order->get_total_tax();
		if ( abs( $tax ) > 0.0001 ) {
			$rows[] = [ __( 'Tax', 'fastnutrition-mealprep' ), self::money( $tax, $order ), false ];
		}
		// Short notes sit beside the totals; long ones get the full width below.
		$wide = ! empty( $notes ) && self::notes_are_long( $notes );
		?>
		<table class="inv-totals-wrap">
			<tr>
				<td class="inv-totals-spacer">
					<?php
					if ( ! empty( $notes ) && ! $wide ) {
						self::render_notes_box( $notes, false );
					}
					?>
				</td>
				<td class="inv-totals-cell">
					<table class="inv-totals">
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td class="inv-totals-label"><?php echo esc_html( (string) $row[0] ); ?></td>
								<td class="inv-totals-value"><?php echo esc_html( (string) $row[1] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr class="inv-total-row">
							<td class="inv-totals-label"><?php esc_html_e( 'Total', 'fastnutrition-mealprep' ); ?></td>
							<td class="inv-totals-value"><?php echo esc_html( self::money( (float) $order->get_total(), $order ) ); ?></td>
						</tr>
						<?php
						$refunded = (float) $order->get_total_refunded();
						if ( $refunded > 0 ) :
							?>
							<tr class="inv-totals-sep">
								<td class="inv-totals-label"><?php esc_html_e( 'Refunded', 'fastnutrition-mealprep' ); ?></td>
								<td class="inv-totals-value">−<?php echo esc_html( self::money( $refunded, $order ) ); ?></td>
							</tr>
							<tr>
								<td class="inv-totals-label"><strong><?php esc_html_e( 'Net total', 'fastnutrition-mealprep' ); ?></strong></td>
								<td class="inv-totals-value"><strong><?php echo esc_html( self::money( (float) $order->get_total() - $refunded, $order ) ); ?></strong></td>
							</tr>
						<?php endif; ?>
					</table>
				</td>
			</tr>
		</table>
		<?php
		if ( $wide ) {
			self::render_notes_box( $notes, true );
		}
	}

	/**
	 * Whether the notes deserve the full page width.
	 *
	 * Short notes sit beside the totals, which keeps a typical order on one
	 * sheet. Past this size the 106mm column wraps every sentence twice, so the
	 * box goes full-width under the summary instead: fewer wrapped lines, and a
	 * plain block that Dompdf can carry over onto a second page if a customer
	 * really does write an essay — nothing is ever cut off.
	 *
	 * @param array<int, array{label: string, text: string}> $notes
	 */
	private static function notes_are_long( array $notes ): bool {
		$lines = 0;
		$chars = 0;
		foreach ( $notes as $entry ) {
			$text   = (string) $entry['text'];
			$lines += count( preg_split( '/\R/', $text ) ?: [] ) + 1; // +1 for the heading.
			$chars += mb_strlen( $text );
		}
		return $lines > 8 || $chars > 450;
	}

	/**
	 * The bordered ORDER NOTES box, beside the totals or full-width under them.
	 *
	 * @param array<int, array{label: string, text: string}> $notes
	 * @param bool                                            $wide  Full-width variant.
	 */
	private static function render_notes_box( array $notes, bool $wide ): void {
		echo '<div class="inv-note' . ( $wide ? ' inv-note--wide' : '' ) . '">';
		foreach ( $notes as $i => $entry ) {
			// Title and text each on one source line: Dompdf turns whitespace
			// around block content into extra line boxes.
			$title = '' !== $entry['label'] ? $entry['label'] : __( 'Order notes', 'fastnutrition-mealprep' );
			echo '<div class="inv-note-title' . ( $i > 0 ? ' inv-note-title--next' : '' ) . '">' . esc_html( $title ) . '</div>';
			echo '<div class="inv-note-text">' . nl2br( esc_html( $entry['text'] ) ) . '</div>';
		}
		echo '</div>';
	}

	private static function render_foot( array $brand ): void {
		$company = trim( (string) ( $brand['company'] ?? '' ) );
		$address = trim( (string) ( $brand['address'] ?? '' ) );
		$web     = trim( (string) ( $brand['web'] ?? '' ) );
		$email   = trim( (string) ( $brand['email'] ?? '' ) );
		$phone   = trim( (string) ( $brand['phone'] ?? '' ) );

		// Email + phone share one line, each prefixed with an icon glyph that
		// Dompdf's bundled DejaVu Sans renders reliably (✉ U+2709, ☎ U+260E).
		$contact = [];
		if ( '' !== $email ) {
			$contact[] = '✉ ' . esc_html( $email );
		}
		if ( '' !== $phone ) {
			$contact[] = '☎ ' . esc_html( $phone );
		}

		// Flatten the multi-line brand address onto one footer line.
		$address_line = '' !== $address ? implode( ', ', array_filter( array_map( 'trim', preg_split( '/\R/', $address ) ?: [] ) ) ) : '';
		?>
		<div class="inv-foot">
			<div class="inv-foot-thanks"><?php esc_html_e( 'Thank you for your order.', 'fastnutrition-mealprep' ); ?></div>
			<?php if ( '' !== $company || '' !== $address_line ) : ?>
				<div class="inv-foot-line">
					<?php echo esc_html( trim( $company . ( '' !== $company && '' !== $address_line ? ' · ' : '' ) . $address_line ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $contact ) || '' !== $web ) : ?>
				<div class="inv-foot-line">
					<?php
					$parts = $contact;
					if ( '' !== $web ) {
						$parts[] = esc_html( $web );
					}
					echo implode( ' &nbsp;&nbsp; ', $parts );
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The trading name printed on the invoice. Comes from the Branding settings
	 * when set, otherwise falls back to the site title.
	 */
	private static function company_name(): string {
		$name = trim( (string) get_option( SettingsPage::OPTION_BRAND_COMPANY, '' ) );
		if ( '' === $name ) {
			$name = trim( (string) get_bloginfo( 'name' ) );
		}
		/**
		 * Filter the company name printed at the head and foot of invoices.
		 *
		 * @param string $name
		 */
		return (string) apply_filters( 'fn_mealprep_invoice_company_name', $name );
	}

	/**
	 * Format an amount in the order's currency as plain text.
	 *
	 * wc_price() returns markup with HTML entities; Dompdf renders the entities
	 * fine but the wrapper spans carry theme classes we don't want leaking into
	 * the PDF, so strip to a bare "£12.34" string.
	 */
	private static function money( float $amount, \WC_Order $order ): string {
		$html = wc_price( $amount, [ 'currency' => $order->get_currency() ] );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}
}

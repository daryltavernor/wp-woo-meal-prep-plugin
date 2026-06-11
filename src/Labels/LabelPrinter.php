<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Labels;

use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\Cart\Selection;
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
	/** Meal labels only — skips the summary page. Useful for testing the meal design in isolation. */
	public const MODE_MEAL    = 'meal';

	/** Physical label stock — 100 mm square direct-thermal. */
	private const LABEL_SIDE_MM = 100.0;

	/** Print head resolution. 203 dpi = 8 dots per mm; a 100 mm side is 800 dots. */
	private const PRINTER_DPI = 203;

	/**
	 * Stream a labels PDF to the browser and exit.
	 *
	 * Always sends no-cache headers so the browser never re-displays a stale
	 * PDF from a previous click — when iterating on label design the URL is
	 * often identical and would otherwise hit the disk cache.
	 *
	 * @param int[]  $order_ids             Order IDs to render.
	 * @param string $mode                  MODE_FULL | MODE_SUMMARY | MODE_MEAL.
	 * @param int    $limit_meals_per_order If > 0, render at most this many
	 *                                      meal labels per order. Used by the
	 *                                      "test print" bulk actions so a
	 *                                      50-meal order doesn't print 50 test
	 *                                      labels.
	 */
	public static function stream( array $order_ids, string $mode = self::MODE_FULL, int $limit_meals_per_order = 0, bool $is_test = false ): void {
		$orders = array_values( array_filter( array_map( static fn( $id ) => wc_get_order( (int) $id ), $order_ids ) ) );
		self::stream_orders( $orders, $mode, $limit_meals_per_order, $is_test );
	}

	/**
	 * Stream a labels PDF for a single in-memory order object — used by the Quick
	 * Label Maker, which never persists an order. The label output is identical
	 * to an order's labels (summary + meal labels, including the payment block).
	 */
	public static function stream_order_object( \WC_Order $order, string $mode = self::MODE_FULL, bool $is_test = false ): void {
		self::stream_orders( [ $order ], $mode, 0, $is_test );
	}

	/**
	 * @param \WC_Order[] $orders
	 */
	private static function stream_orders( array $orders, string $mode = self::MODE_FULL, int $limit_meals_per_order = 0, bool $is_test = false ): void {
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install in the plugin directory.', 'fastnutrition-mealprep' ) );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$html   = self::build_html( $orders, $mode, $limit_meals_per_order, $is_test );
		$dompdf = new \Dompdf\Dompdf(
			[
				// Remote fetching stays OFF: the brand logo is inlined as a base64
				// data URI (see logo_data_uri()) and labels carry no other external
				// resources. Leaving it on would let any unescaped order field that
				// reached the markup (e.g. an <img> in a customer-supplied value)
				// make the server fetch an attacker URL when staff print labels —
				// an SSRF vector — for zero functional benefit.
				'isRemoteEnabled'      => false,
				'isPhpEnabled'         => false,
				'defaultMediaType'     => 'print',
				'isHtml5ParserEnabled' => true,
				// Match the print head so the inlined logo is sampled at the device
				// resolution rather than Dompdf's 96 dpi screen default, so it
				// doesn't print soft.
				'dpi'                  => self::PRINTER_DPI,
				// Keep the chroot scoped to the WP install for any local file Dompdf
				// may need to resolve, never the whole filesystem.
				'chroot'               => [ ABSPATH, WP_CONTENT_DIR ],
			]
		);
		$dompdf->loadHtml( $html );
		// Convert the mm side to points (1 pt = 1/72 in, 1 in = 25.4 mm).
		$side_pt = self::LABEL_SIDE_MM * 72 / 25.4;
		$dompdf->setPaper( [ 0, 0, $side_pt, $side_pt ], 'portrait' );
		$dompdf->render();

		$pdf      = (string) $dompdf->output();
		$prefix   = match ( $mode ) {
			self::MODE_SUMMARY => 'summary-labels',
			self::MODE_MEAL    => 'meal-labels',
			default            => 'labels',
		};
		// Unique filename + nocache_headers() together defeat both the URL
		// cache and any "open recent PDF" reuse in the viewer.
		$filename = $prefix . '-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false ) . '.pdf';

		// Test prints force a download so the mobile PDF viewer (notorious
		// for caching by URL) can never serve a stale file — each download
		// has a unique filename and the OS treats it as a new document.
		$disposition = $is_test ? 'attachment' : 'inline';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );
		echo $pdf;
		exit;
	}

	/**
	 * Renders the label HTML directly to the browser (no PDF rasterisation).
	 *
	 * Use this for design iteration. Same CSS as the PDF, but the response
	 * is normal HTML, so it bypasses every PDF viewer cache and lets you
	 * inspect the layout in browser DevTools. Refresh the tab to see the
	 * latest design after a code change.
	 */
	public static function stream_html( array $order_ids, string $mode = self::MODE_FULL, int $limit_meals_per_order = 0 ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$orders = array_values( array_filter( array_map( static fn( $id ) => wc_get_order( (int) $id ), $order_ids ) ) );
		$html   = self::build_html( $orders, $mode, $limit_meals_per_order, true );

		// Inject a screen-mode stylesheet so each .label looks like a real
		// label card on a grey background. Print-mode CSS is untouched —
		// the @page rules already in <style> handle the PDF case.
		$screen_css = <<<'CSS'
<style>
@media screen {
	html { background: #ddd; padding: 24px; margin: 0; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; }
	body { background: transparent; margin: 0; padding: 0; }
	.preview-banner {
		max-width: 100mm;
		margin: 0 auto 16px;
		padding: 10px 14px;
		background: #fff8e5;
		border: 1px dashed #dba617;
		color: #333;
		font-size: 13px;
		line-height: 1.45;
	}
	.preview-banner strong { color: #000; }
	.preview-banner code { background: #fff; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
	.label {
		background: #fff;
		margin: 0 auto 16px;
		box-shadow: 0 2px 12px rgba(0,0,0,0.25);
		border: 1px dashed #999;
	}
}
@media print {
	.preview-banner { display: none; }
}
</style>
CSS;

		$banner_msg  = sprintf(
			/* translators: 1: plugin version, 2: render timestamp */
			esc_html__( 'Rendered from plugin %1$s at %2$s. Refresh this tab (⌘R / Ctrl+R) to re-render with the latest code.', 'fastnutrition-mealprep' ),
			'<code>v' . esc_html( defined( 'FN_MEALPREP_VERSION' ) ? FN_MEALPREP_VERSION : '?' ) . '</code>',
			'<code>' . esc_html( wp_date( 'H:i:s' ) ) . '</code>'
		);
		$banner = '<div class="preview-banner"><strong>' . esc_html__( 'Label preview', 'fastnutrition-mealprep' ) . '</strong> — ' . $banner_msg . '</div>';

		$html = str_replace( '</head>', $screen_css . '</head>', $html );
		$html = preg_replace( '/<body([^>]*)>/', '<body$1>' . $banner, $html, 1 );

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $html;
		exit;
	}

	/**
	 * @param \WC_Order[] $orders
	 */
	private static function build_html( array $orders, string $mode = self::MODE_FULL, int $limit_meals_per_order = 0, bool $is_test = false ): string {
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
	/* Layout uses position-based content boxes with EXPLICIT widths
	   in mm — NOT padding + box-sizing arithmetic. Dompdf was not
	   constraining children to the padding-edge of .label, so meal
	   descriptions rendered past the 95mm right edge and the summary
	   content overflowed onto a second page. Explicit widths give
	   Dompdf nothing to get wrong. */
	.label {
		width: 100mm;
		height: 100mm;
		position: relative;
		overflow: hidden;
	}
	.label + .label { page-break-before: always; }
	/* 90 x 70 mm body box, leaving 25 mm at the bottom for the foot. */
	.label-body {
		position: absolute;
		top: 5mm;
		left: 5mm;
		width: 90mm;
		height: 70mm;
		overflow: hidden;
	}
	/* Head holds the logo on the left. The counter ("1/10") sits on the
	   right for meal labels and is empty on the summary. Order # has
	   moved out of the head — it now sits inline with the customer
	   name to save horizontal space. */
	.lbl-head {
		width: 90mm;
		border-collapse: collapse;
		border-bottom: 1px solid #000;
		margin: 0 0 1.5mm;
	}
	.lbl-head td {
		padding: 0 0 1.5mm;
		vertical-align: middle;
	}
	.lbl-head-logo img {
		max-width: 60mm;
		max-height: 12mm;
	}
	.lbl-head-counter {
		text-align: right;
		font-size: 10pt;
		font-weight: bold;
		width: 20mm;
	}
	.lbl-name {
		width: 90mm;
		font-size: 11pt;
		font-weight: bold;
		margin-bottom: 1mm;
		line-height: 1.2;
		word-wrap: break-word;
		overflow-wrap: break-word;
	}
	.lbl-name-id { margin-right: 2mm; }
	.lbl-desc {
		width: 90mm;
		font-size: 11pt;
		font-weight: bold;
		line-height: 1.2;
		margin-bottom: 1mm;
		word-wrap: break-word;
		overflow-wrap: break-word;
	}
	.lbl-addons {
		width: 90mm;
		font-size: 8pt;
		font-style: italic;
		color: #000;
		margin-bottom: 1mm;
		word-wrap: break-word;
	}
	.lbl-macros {
		width: 90mm;
		font-size: 9pt;
		line-height: 1.3;
		border: 1px solid #000;
		padding: 1mm 1.5mm;
		margin-bottom: 1mm;
		box-sizing: border-box;
		word-wrap: break-word;
	}
	.lbl-macros strong { font-weight: bold; }
	.lbl-address {
		width: 90mm;
		font-size: 9pt;
		line-height: 1.3;
		margin-bottom: 1mm;
		word-wrap: break-word;
	}
	.lbl-customer-contact {
		width: 90mm;
		font-size: 8pt;
		color: #000;
		margin-bottom: 1mm;
		word-wrap: break-word;
	}
	.lbl-count {
		width: 90mm;
		text-align: center;
		font-size: 16pt;
		font-weight: bold;
		letter-spacing: 0.5mm;
		padding: 1mm 0;
		margin: 0 0 1mm;
		border-top: 1px solid #000;
		border-bottom: 1px solid #000;
		box-sizing: border-box;
	}
	.lbl-fulfilment {
		width: 90mm;
		font-size: 9pt;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.2mm;
		margin-bottom: 0;
	}
	/* Food-safety "use by" notice — fulfilment date + 3 days. Mirrors the
	   fulfilment line's prominence so it reads as a firm instruction. */
	.lbl-use-by {
		width: 90mm;
		font-size: 9pt;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.2mm;
		margin-top: 0.5mm;
	}
	/* Combined fine print on meal labels — storage/reheat hint + allergen
	   pointer on a single italic line, in the space between the fulfilment
	   line and the brand foot. Italic + small so it reads as guidance. */
	.lbl-fineprint {
		width: 90mm;
		font-size: 7pt;
		font-style: italic;
		line-height: 1.3;
		margin-top: 1mm;
		text-align: center;
		word-wrap: break-word;
	}
	/* Payment status — asymmetric on purpose.
	   PAID = quiet thin-ruled line. UNPAID = full-width inverted bar
	   so a packer can never miss an unpaid order. */
	.lbl-payment {
		width: 90mm;
		text-align: center;
		margin-bottom: 1mm;
	}
	.lbl-payment--paid {
		font-size: 9pt;
		padding: 0.5mm 0;
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
		padding: 1mm 0;
	}
	.lbl-payment--unpaid .lbl-payment-badge {
		display: block;
		font-size: 12pt;
		font-weight: bold;
		letter-spacing: 1mm;
	}
	.lbl-payment--unpaid .lbl-payment-method {
		display: block;
		font-size: 7pt;
		margin-top: 0.3mm;
	}
	/* Foot is anchored to the bottom 5 mm with EXPLICIT 90 mm width, so it
	   shares the same horizontal box as the body and cannot drift past the
	   page edge or get stranded on a different page. */
	.lbl-foot {
		position: absolute;
		bottom: 5mm;
		left: 5mm;
		width: 90mm;
		font-size: 8pt;
		line-height: 1.3;
		color: #000;
		border-top: 1px solid #000;
		padding-top: 1mm;
		text-align: center;
	}
	.lbl-foot-address { margin-bottom: 0.5mm; }
	.lbl-foot-line { line-height: 1.3; }
	/* Visible on test/preview renders only. Eats ~3mm of vertical space
	   so summary content needs ~67mm instead of 70mm. */
	.lbl-teststamp {
		width: 90mm;
		font-size: 6pt;
		text-align: center;
		color: #000;
		background: #ffc;
		padding: 0.4mm 0;
		margin-bottom: 1mm;
		border: 1px dashed #000;
		box-sizing: border-box;
		letter-spacing: 0.3mm;
	}
	.muted { color: #000; font-style: italic; }
</style>
</head>
<body>
		<?php
		$has_labels = false;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( self::MODE_MEAL !== $mode ) {
				self::render_summary_label( $order, $brand, $is_test );
				$has_labels = true;
			}
			if ( self::MODE_SUMMARY === $mode ) {
				continue;
			}
			// Counter shows position across ALL meals in the order
			// ("3/15"), not the position within a single product line.
			// Calculate total first, then increment a running counter.
			$total_meals = 0;
			foreach ( $order->get_items() as $item ) {
				$total_meals += (int) $item->get_quantity();
			}
			$meal_num = 0;
			foreach ( $order->get_items() as $item ) {
				$qty = (int) $item->get_quantity();
				for ( $i = 1; $i <= $qty; $i++ ) {
					$meal_num++;
					self::render_meal_label( $order, $item, $meal_num, $total_meals, $brand, $is_test );
					$has_labels = true;
					if ( $limit_meals_per_order > 0 && $meal_num >= $limit_meals_per_order ) {
						break 2;
					}
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

	private static function render_summary_label( \WC_Order $order, array $brand, bool $is_test = false ): void {
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
			<div class="label-body">
				<?php self::render_test_stamp( $is_test ); ?>
				<?php self::render_head( $brand, null ); ?>
				<div class="lbl-name">
					<?php if ( $order->get_id() ) : ?>
						<span class="lbl-name-id">#<?php echo (int) $order->get_id(); ?></span>
					<?php endif; ?>
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
			</div>
			<?php self::render_foot( $brand ); ?>
		</div>
		<?php
	}

	private static function render_meal_label( \WC_Order $order, \WC_Order_Item_Product $item, int $idx, int $total, array $brand, bool $is_test = false ): void {
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
		// Sweets carry their own (variable) shelf life, so staff write the USE BY
		// by hand and the cook/reheat guidance doesn't apply — the allergen
		// pointer is kept. All other items keep the standard storage line + an
		// auto-calculated USE BY.
		$is_sweet      = is_array( $selection ) && Selection::is_sweet( $selection );
		$allergen_line = __( 'Allergens & prep: fastnutrition.co.uk/info/allergeninfo.pdf', 'fastnutrition-mealprep' );
		$storage_line  = __( 'Refrigerate up to 3 days or freeze for 3 months · Microwave 3½ min to reheat', 'fastnutrition-mealprep' );
		?>
		<div class="label meal">
			<div class="label-body">
				<?php self::render_test_stamp( $is_test ); ?>
				<?php self::render_head( $brand, $idx . '/' . $total ); ?>
				<div class="lbl-name">
					<?php if ( $order->get_id() ) : ?>
						<span class="lbl-name-id">#<?php echo (int) $order->get_id(); ?></span>
					<?php endif; ?>
					<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
				</div>
				<div class="lbl-desc"><?php echo esc_html( $desc ); ?></div>
				<?php if ( ! empty( $addons ) ) : ?>
					<div class="lbl-addons">+ <?php echo esc_html( implode( ', ', $addons ) ); ?></div>
				<?php endif; ?>
				<div class="lbl-macros">
					<strong>Macros:</strong>
					<?php echo (int) round( (float) $macros['kcal'] ); ?> Kcals,
					<?php echo (int) round( (float) $macros['protein_g'] ); ?>g Protein,
					<?php echo (int) round( (float) $macros['carbs_g'] ); ?>g Carbs,
					<?php echo (int) round( (float) $macros['fat_g'] ); ?>g Fat
				</div>
				<div class="lbl-fulfilment"><?php echo esc_html( self::format_fulfilment( $ff ) ); ?></div>
				<?php if ( $is_sweet ) : ?>
					<div class="lbl-use-by"><strong><?php esc_html_e( 'USE BY:', 'fastnutrition-mealprep' ); ?></strong> </div>
					<div class="lbl-fineprint"><?php echo esc_html( $allergen_line ); ?></div>
				<?php else : ?>
					<?php $use_by = self::use_by_text( $ff ); ?>
					<?php if ( '' !== $use_by ) : ?>
						<div class="lbl-use-by"><strong><?php esc_html_e( 'USE BY:', 'fastnutrition-mealprep' ); ?></strong> <?php echo esc_html( $use_by ); ?></div>
					<?php endif; ?>
					<div class="lbl-fineprint"><?php echo esc_html( $storage_line . ' · ' . $allergen_line ); ?></div>
				<?php endif; ?>
			</div>
			<?php self::render_foot( $brand ); ?>
		</div>
		<?php
	}

	/**
	 * Tiny banner shown only on test prints / HTML previews. Carries the
	 * plugin version + render timestamp so the user can verify with their
	 * own eyes whether the response is fresh (vs. served from a cache).
	 */
	private static function render_test_stamp( bool $is_test ): void {
		if ( ! $is_test ) {
			return;
		}
		$version = defined( 'FN_MEALPREP_VERSION' ) ? FN_MEALPREP_VERSION : '?';
		?>
		<div class="lbl-teststamp">
			TEST · v<?php echo esc_html( $version ); ?> · <?php echo esc_html( wp_date( 'H:i:s' ) ); ?>
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
		// Email + phone share one line, each prefixed with an icon glyph that
		// Dompdf's bundled DejaVu Sans renders reliably (✉ U+2709, ☎ U+260E).
		$contact = [];
		if ( '' !== $email ) {
			$contact[] = '✉ ' . esc_html( $email );
		}
		if ( '' !== $phone ) {
			$contact[] = '☎ ' . esc_html( $phone );
		}
		?>
		<div class="lbl-foot">
			<?php if ( '' !== $address ) : ?>
				<div class="lbl-foot-address"><?php echo nl2br( esc_html( $address ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $web ) : ?>
				<div class="lbl-foot-line"><?php echo esc_html( $web ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $contact ) ) : ?>
				<div class="lbl-foot-line"><?php echo implode( ' &nbsp;&nbsp; ', $contact ); ?></div>
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

	/**
	 * "Use by" date for a meal label: a 3-day shelf life where the fulfilment
	 * (collection/delivery) day counts as day 1 — i.e. fulfilment date + 2 days.
	 * So collect on the 1st → use by the 3rd. Formatted like the fulfilment line
	 * (e.g. "WED 9 JUN"). Returns '' when there is no fulfilment date.
	 */
	private static function use_by_text( mixed $ff ): string {
		if ( ! is_array( $ff ) ) {
			return '';
		}
		$date = (string) ( $ff['date'] ?? '' );
		if ( '' === $date ) {
			return '';
		}
		$ts = strtotime( $date . ' +2 days' );
		if ( false === $ts ) {
			return '';
		}
		return wp_date( 'D j M', $ts );
	}
}

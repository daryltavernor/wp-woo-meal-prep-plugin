<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\SettingsPage;

/**
 * The Cook & Pack Record: a printable (A4 landscape) food-safety record for a
 * prep day, pre-filled with everything the system already knows — one row per
 * component from the day's prep list, its batch code, cooking method, portion
 * count, target core temperature and use-by — with the measurements either
 * blank for handwriting, taken from the saved Prep Records, or, for
 * demonstration, filled with sample readings.
 *
 * The same renderer serves the admin preview, the browser print and the PDF.
 */
final class CookPackRecord {

	public const PAGE_SLUG = 'fn-cook-pack-record';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_pdf' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Rows --------------------------------------------------------------------

	/**
	 * One row per component on the day's prep list, with its batch code and
	 * resolved food-safety values. Proteins and set meals first, then carbs,
	 * greens and sweets, largest batch first within each group.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( string $date ): array {
		$totals = PrepDashboard::get_day_totals( $date );
		if ( empty( $totals ) ) {
			return [];
		}
		$codes = BatchCodes::map_for_day( $date, $totals );
		$order = [ 'protein' => 0, 'set_meal' => 1, 'carb' => 2, 'greens' => 3, 'sweet' => 4 ];
		$rows  = [];
		foreach ( $totals as $t ) {
			$iid  = (int) $t['ingredient_id'];
			$type = (string) ( $t['type_slug'] ?? '' );
			$safe = IngredientSafety::resolve( $iid, $type );
			$rows[] = [
				'batch_code'      => $codes[ $iid ] ?? '',
				'seq'             => 1,
				'ingredient_id'   => $iid,
				'name'            => (string) $t['name'],
				'type_slug'       => $type,
				'method'          => $safe['method'],
				'method_label'    => $safe['method_label'],
				'no_cook'         => $safe['no_cook'],
				'portions'        => (int) $t['portions'],
				'target_c'        => $safe['target_c'],
				'cooling'         => $safe['cooling'],
				'typical'         => $safe['typical'],
				'storage'         => $safe['storage'],
				'shelf_life_days' => $safe['shelf_life_days'],
				'use_by'          => self::use_by( $date, $safe['shelf_life_days'] ),
			];
		}
		usort(
			$rows,
			static fn( array $a, array $b ): int => [ $order[ $a['type_slug'] ] ?? 9, $b['portions'] ] <=> [ $order[ $b['type_slug'] ] ?? 9, $a['portions'] ]
		);
		return $rows;
	}

	/** Use-by: the fulfilment day counts as day 1 of the shelf life. */
	public static function use_by( string $date, int $shelf_days ): string {
		$ts = strtotime( $date . ' 12:00:00 +' . max( 0, $shelf_days - 1 ) . ' days' );
		return false !== $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/**
	 * The day's rows merged with saved Prep Records (including extra batches
	 * staff added), optionally sample-filled where nothing has been recorded.
	 *
	 * @param bool $contains_sample Set true when any returned row holds sample values.
	 * @return array<int,array<string,mixed>>
	 */
	public static function merged_rows( string $date, bool $with_sample, bool &$contains_sample = false ): array {
		$base     = self::rows( $date );
		$saved    = PrepRecordsRepository::get_day( $date );
		$settings = FoodSafetySettings::get();
		$out      = [];
		$seen_ids = [];

		foreach ( $base as $row ) {
			$iid        = (int) $row['ingredient_id'];
			$seen_ids[] = $iid;
			$matches    = array_filter( $saved, static fn( array $r ): bool => (int) $r['ingredient_id'] === $iid );
			if ( empty( $matches ) ) {
				$r = $row;
				if ( $with_sample ) {
					$r               = array_merge( $r, SampleReadings::for_row( $date, $row, $settings ) );
					$contains_sample = true;
				}
				$out[] = $r;
				continue;
			}
			uasort( $matches, static fn( array $a, array $b ): int => BatchCodes::sequence( $a['batch_code'] ) <=> BatchCodes::sequence( $b['batch_code'] ) );
			foreach ( $matches as $m ) {
				$r = array_merge(
					$row,
					[
						'batch_code' => $m['batch_code'],
						'seq'        => BatchCodes::sequence( $m['batch_code'] ),
						'use_by'     => '' !== $m['use_by'] ? $m['use_by'] : $row['use_by'],
					],
					PrepRecordsRepository::entered_fields( $m )
				);
				if ( $m['is_sample'] ) {
					$contains_sample = true;
				}
				$out[] = $r;
			}
		}

		// Batches recorded for components no longer on the prep list (an order
		// cancelled after cooking) still belong on the day's record.
		foreach ( $saved as $m ) {
			if ( in_array( (int) $m['ingredient_id'], $seen_ids, true ) ) {
				continue;
			}
			$out[] = array_merge( self::row_from_record( $m ), PrepRecordsRepository::entered_fields( $m ) );
			if ( $m['is_sample'] ) {
				$contains_sample = true;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $m A saved record.
	 * @return array<string,mixed>
	 */
	private static function row_from_record( array $m ): array {
		$no_cook = 'no_cook' === $m['method'];
		return [
			'batch_code'      => $m['batch_code'],
			'seq'             => BatchCodes::sequence( $m['batch_code'] ),
			'ingredient_id'   => (int) $m['ingredient_id'],
			'name'            => $m['component'],
			'type_slug'       => '',
			'method'          => $m['method'],
			'method_label'    => FoodSafetySettings::method_label( (string) $m['method'] ),
			'no_cook'         => $no_cook,
			'portions'        => (int) $m['portions'],
			'target_c'        => $m['target_c'],
			'cooling'         => ! $no_cook,
			'typical'         => '',
			'storage'         => 'chilled',
			'shelf_life_days' => 0,
			'use_by'          => (string) $m['use_by'],
		];
	}

	// --- Admin page ------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		$sample = ! empty( $_GET['sample'] );
		// phpcs:enable
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = wp_date( 'Y-m-d' );
		}

		$contains_sample = false;
		$rows            = self::merged_rows( $date, $sample, $contains_sample );
		$checks          = PrepRecordsRepository::get_checks( $date );
		$settings        = FoodSafetySettings::get();
		if ( $sample && empty( $checks ) ) {
			$checks          = SampleReadings::for_checks( $date, $settings );
			$contains_sample = true;
		}
		if ( ! empty( $checks['__sample'] ) ) {
			$contains_sample = true;
		}

		$pdf_url     = self::pdf_url( $date, $sample );
		$records_url = admin_url( 'admin.php?page=' . PrepRecordsPage::PAGE_SLUG . '&date=' . rawurlencode( $date ) );

		echo '<div class="wrap fn-cook-pack-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Cook & Pack Record', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<div class="fn-no-print" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:12px 0;">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page is for', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'The food-safety record for a prep day: one row per cooked component with its batch code, method, portions, target core temperature and use-by, plus the daily fridge and probe checks. Print it with the Prep Sheet and fill in the readings by hand, or enter them on the Prep Records page and print the completed sheet. "Fill with sample readings" shows what a completed day looks like for demonstration; those sheets carry a specimen line and are never saved.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		echo '<form method="get" class="fn-no-print" style="margin:12px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		printf( '<label>%s <input type="date" name="date" value="%s" /></label>', esc_html__( 'Prep date', 'fastnutrition-mealprep' ), esc_attr( $date ) );
		printf( '<label><input type="checkbox" name="sample" value="1" %s /> %s</label>', checked( $sample, true, false ), esc_html__( 'Fill with sample readings (demonstration)', 'fastnutrition-mealprep' ) );
		submit_button( __( 'Update', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo ' <button type="button" class="button" onclick="window.print()">' . esc_html__( 'Print', 'fastnutrition-mealprep' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Download PDF', 'fastnutrition-mealprep' ) . '</a>';
		echo ' <a class="button" href="' . esc_url( $records_url ) . '">' . esc_html__( 'Enter readings (Prep Records)', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form>';

		self::print_styles( false );
		echo '<div class="fn-cpr-paper">';
		self::render_sheet( $date, $rows, $checks, $settings, $contains_sample );
		echo '</div></div>';
	}

	public static function pdf_url( string $date, bool $sample ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=pdf&date=' . rawurlencode( $date ) . ( $sample ? '&sample=1' : '' ) ),
			'fn_cook_pack_pdf'
		);
	}

	public function maybe_handle_pdf(): void {
		if ( ! isset( $_GET['page'], $_GET['action'] ) || self::PAGE_SLUG !== $_GET['page'] || 'pdf' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_cook_pack_pdf' );
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install in the plugin directory.', 'fastnutrition-mealprep' ) );
		}
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		$sample = ! empty( $_GET['sample'] );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = wp_date( 'Y-m-d' );
		}

		$html = self::build_pdf_html( $date, $sample );

		$dompdf = new \Dompdf\Dompdf(
			[
				'isRemoteEnabled'      => false,
				'isPhpEnabled'         => false,
				'defaultMediaType'     => 'print',
				'isHtml5ParserEnabled' => true,
				'chroot'               => [ ABSPATH, WP_CONTENT_DIR ],
			]
		);
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();
		nocache_headers();
		$dompdf->stream( 'cook-pack-record-' . $date . ( $sample ? '-specimen' : '' ) . '.pdf', [ 'Attachment' => false ] );
		exit;
	}

	/** Complete HTML document for the PDF (also used by the archive's "print day"). */
	public static function build_pdf_html( string $date, bool $sample ): string {
		$contains_sample = false;
		$rows            = self::merged_rows( $date, $sample, $contains_sample );
		$checks          = PrepRecordsRepository::get_checks( $date );
		$settings        = FoodSafetySettings::get();
		if ( $sample && empty( $checks ) ) {
			$checks          = SampleReadings::for_checks( $date, $settings );
			$contains_sample = true;
		}
		if ( ! empty( $checks['__sample'] ) ) {
			$contains_sample = true;
		}
		ob_start();
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . esc_html__( 'Cook & Pack Record', 'fastnutrition-mealprep' ) . ' ' . esc_html( $date ) . '</title>';
		self::print_styles( true );
		echo '</head><body>';
		self::render_sheet( $date, $rows, $checks, $settings, $contains_sample );
		echo '</body></html>';
		return (string) ob_get_clean();
	}

	// --- The sheet -------------------------------------------------------------------

	/**
	 * @param array<int,array<string,mixed>> $rows     From merged_rows().
	 * @param array<string,string>           $checks   From PrepRecordsRepository::get_checks() or SampleReadings::for_checks().
	 * @param array<string,mixed>            $settings FoodSafetySettings::get().
	 */
	public static function render_sheet( string $date, array $rows, array $checks, array $settings, bool $contains_sample ): void {
		$ts         = strtotime( $date . ' 12:00:00' );
		$date_label = false !== $ts ? wp_date( 'l j F Y', $ts ) : $date;
		$company    = trim( (string) get_option( SettingsPage::OPTION_BRAND_COMPANY, '' ) );
		if ( '' === $company ) {
			$company = (string) get_bloginfo( 'name' );
		}
		$calibration_day = FoodSafetySettings::is_calibration_day( $date );
		$signoff         = (string) ( $checks['signoff'] ?? '' );

		echo '<div class="cpr">';
		// Header.
		echo '<table class="cpr-head"><tr>';
		echo '<td class="cpr-head-l"><div class="cpr-title">' . esc_html__( 'COOK & PACK RECORD', 'fastnutrition-mealprep' ) . '</div>';
		echo '<div class="cpr-sub">' . esc_html( $company ) . ' · ' . esc_html( $date_label ) . '</div></td>';
		echo '<td class="cpr-head-r">';
		printf(
			'<div class="cpr-sign">%s (%s): <span class="cpr-blank">%s</span></div>',
			esc_html__( 'Signed off', 'fastnutrition-mealprep' ),
			esc_html( (string) $settings['signatory'] ),
			esc_html( '' !== $signoff ? $signoff : "\u{00A0}" )
		);
		printf(
			'<div class="cpr-limits">%s</div>',
			esc_html(
				sprintf(
					/* translators: 1: cook temp, 2: cool minutes, 3: fridge max */
					__( 'Limits: core ≥ %1$s °C · refrigerate within %2$d min · fridge ≤ %3$s °C', 'fastnutrition-mealprep' ),
					(string) $settings['cook_core_c'],
					(int) $settings['cool_within_min'],
					(string) $settings['fridge_max_c']
				)
			)
		);
		echo '</td></tr></table>';

		// Daily checks block.
		echo '<table class="cpr-checks"><tr>';
		echo '<td class="cpr-checks-units"><div class="cpr-h">' . esc_html__( 'Fridge & freezer checks', 'fastnutrition-mealprep' ) . '</div>';
		echo '<table class="cpr-units">';
		printf(
			'<tr><th></th><th>%s <span class="cpr-blank cpr-time">%s</span></th><th>%s <span class="cpr-blank cpr-time">%s</span></th></tr>',
			esc_html__( 'AM', 'fastnutrition-mealprep' ),
			esc_html( (string) ( $checks['am_time'] ?? "\u{00A0}" ) ),
			esc_html__( 'PM', 'fastnutrition-mealprep' ),
			esc_html( (string) ( $checks['pm_time'] ?? "\u{00A0}" ) )
		);
		foreach ( array_merge( (array) $settings['fridges'], (array) $settings['freezers'] ) as $unit ) {
			printf(
				'<tr><td>%s</td><td><span class="cpr-blank cpr-temp">%s</span> °C</td><td><span class="cpr-blank cpr-temp">%s</span> °C</td></tr>',
				esc_html( (string) $unit ),
				esc_html( self::fmt_num( $checks[ 'unit_am:' . $unit ] ?? '' ) ),
				esc_html( self::fmt_num( $checks[ 'unit_pm:' . $unit ] ?? '' ) )
			);
		}
		echo '</table></td>';
		echo '<td class="cpr-checks-other"><div class="cpr-h">' . esc_html__( 'Probe calibration', 'fastnutrition-mealprep' ) . ( $calibration_day ? ' <em>' . esc_html__( '(due today)', 'fastnutrition-mealprep' ) . '</em>' : '' ) . '</div>';
		printf(
			'<div>%s <span class="cpr-blank cpr-temp">%s</span> °C &nbsp; %s <span class="cpr-blank cpr-temp">%s</span> °C</div>',
			esc_html__( 'Iced water', 'fastnutrition-mealprep' ),
			esc_html( self::fmt_num( $checks['probe_iced'] ?? '' ) ),
			esc_html__( 'Boiling', 'fastnutrition-mealprep' ),
			esc_html( self::fmt_num( $checks['probe_boiling'] ?? '' ) )
		);
		echo '<div class="cpr-h" style="margin-top:2mm">' . esc_html__( 'Daily checks', 'fastnutrition-mealprep' ) . '</div>';
		printf(
			'<div>%s %s &nbsp; %s %s &nbsp; %s %s</div>',
			self::tick( ! empty( $checks['opening'] ) ),
			esc_html__( 'Opening checks', 'fastnutrition-mealprep' ),
			self::tick( ! empty( $checks['closing'] ) ),
			esc_html__( 'Closing checks', 'fastnutrition-mealprep' ),
			self::tick( ! empty( $checks['cleaning'] ) ),
			esc_html__( 'Cleaning schedule done', 'fastnutrition-mealprep' )
		);
		if ( ! empty( $checks['notes'] ) ) {
			echo '<div class="cpr-notes">' . esc_html( (string) $checks['notes'] ) . '</div>';
		}
		echo '</td></tr></table>';

		// Batch table.
		echo '<table class="cpr-batches"><thead><tr>';
		foreach ( [
			__( 'Batch', 'fastnutrition-mealprep' ),
			__( 'Component', 'fastnutrition-mealprep' ),
			__( 'Method', 'fastnutrition-mealprep' ),
			__( 'Portions', 'fastnutrition-mealprep' ),
			__( 'Target', 'fastnutrition-mealprep' ),
			__( 'Time in', 'fastnutrition-mealprep' ),
			__( 'Time out', 'fastnutrition-mealprep' ),
			__( 'Core 1', 'fastnutrition-mealprep' ),
			__( 'Core 2', 'fastnutrition-mealprep' ),
			__( 'Cooled by', 'fastnutrition-mealprep' ),
			__( 'Fridge', 'fastnutrition-mealprep' ),
			__( 'Packed', 'fastnutrition-mealprep' ),
			__( 'Use-by', 'fastnutrition-mealprep' ),
			__( 'Init.', 'fastnutrition-mealprep' ),
		] as $i => $h ) {
			echo '<th class="c' . (int) $i . '">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="14"><em>' . esc_html__( 'No components on the prep list for this date.', 'fastnutrition-mealprep' ) . '</em></td></tr>';
		}
		$out_of_limit = [];
		foreach ( $rows as $r ) {
			$code  = BatchCodes::short( (string) $r['batch_code'] );
			$flag  = ! empty( $r['out_of_limit'] );
			$class = $flag ? ' class="cpr-flag"' : '';
			if ( $flag ) {
				$out_of_limit[] = $r;
			}
			if ( ! empty( $r['no_cook'] ) ) {
				printf(
					'<tr%1$s><td class="c0">%2$s</td><td class="c1"><strong>%3$s</strong></td><td class="c2">%4$s</td><td class="c3 num">%5$d</td><td colspan="8" class="cpr-nocook">%6$s <span class="cpr-blank cpr-time">%7$s</span> &nbsp; %8$s <span class="cpr-blank cpr-time">%9$s</span> &nbsp; %10$s <span class="cpr-blank cpr-date">%11$s</span></td><td class="c13"><span class="cpr-blank cpr-init">%12$s</span></td></tr>',
					$class,
					esc_html( $code ),
					esc_html( (string) $r['name'] ),
					esc_html( (string) $r['method_label'] ),
					(int) $r['portions'],
					esc_html__( 'No cook — ready to eat. Supplier use-by checked at', 'fastnutrition-mealprep' ),
					esc_html( (string) ( $r['time_in'] ?? '' ) ?: "\u{00A0}" ),
					esc_html__( 'packed', 'fastnutrition-mealprep' ),
					esc_html( (string) ( $r['packed_at'] ?? '' ) ?: "\u{00A0}" ),
					esc_html__( 'use-by', 'fastnutrition-mealprep' ),
					esc_html( self::fmt_date( (string) ( $r['use_by'] ?? '' ) ) ),
					esc_html( (string) ( $r['initials'] ?? '' ) ?: "\u{00A0}" )
				);
				continue;
			}
			$cooling = ! empty( $r['cooling'] );
			printf(
				'<tr%1$s><td class="c0">%2$s</td><td class="c1"><strong>%3$s</strong>%4$s</td><td class="c2">%5$s</td><td class="c3 num">%6$d</td><td class="c4 num">%7$s °C</td><td class="c5"><span class="cpr-blank cpr-time">%8$s</span></td><td class="c6"><span class="cpr-blank cpr-time">%9$s</span></td><td class="c7"><span class="cpr-blank cpr-temp">%10$s</span></td><td class="c8"><span class="cpr-blank cpr-temp">%11$s</span></td><td class="c9">%12$s</td><td class="c10">%13$s</td><td class="c11"><span class="cpr-blank cpr-time">%14$s</span></td><td class="c12"><span class="cpr-blank cpr-date">%15$s</span></td><td class="c13"><span class="cpr-blank cpr-init">%16$s</span></td></tr>',
				$class,
				esc_html( $code ),
				esc_html( (string) $r['name'] ),
				'' !== (string) ( $r['typical'] ?? '' ) ? '<br><em class="cpr-typical">' . esc_html( (string) $r['typical'] ) . '</em>' : '',
				esc_html( (string) $r['method_label'] ),
				(int) $r['portions'],
				esc_html( self::fmt_num( $r['target_c'] ?? '' ) ),
				esc_html( (string) ( $r['time_in'] ?? '' ) ?: "\u{00A0}" ),
				esc_html( (string) ( $r['time_out'] ?? '' ) ?: "\u{00A0}" ),
				esc_html( self::fmt_num( $r['probe1_c'] ?? '' ) ),
				esc_html( self::fmt_num( $r['probe2_c'] ?? '' ) ),
				$cooling ? '<span class="cpr-blank cpr-time">' . esc_html( (string) ( $r['cooled_by'] ?? '' ) ?: "\u{00A0}" ) . '</span>' : '<span class="cpr-na">—</span>',
				$cooling ? '<span class="cpr-blank cpr-unit">' . esc_html( (string) ( $r['fridge_unit'] ?? '' ) ?: "\u{00A0}" ) . '</span>' : '<span class="cpr-na">—</span>',
				esc_html( (string) ( $r['packed_at'] ?? '' ) ?: "\u{00A0}" ),
				esc_html( self::fmt_date( (string) ( $r['use_by'] ?? '' ) ) ),
				esc_html( (string) ( $r['initials'] ?? '' ) ?: "\u{00A0}" )
			);
			if ( $flag && '' !== (string) ( $r['corrective_action'] ?? '' ) ) {
				echo '<tr class="cpr-flag"><td></td><td colspan="13" class="cpr-action"><strong>' . esc_html__( 'Action taken:', 'fastnutrition-mealprep' ) . '</strong> ' . esc_html( (string) $r['corrective_action'] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// Corrective actions / notes line.
		echo '<div class="cpr-foot">';
		if ( empty( $out_of_limit ) ) {
			echo '<div class="cpr-h">' . esc_html__( 'Out-of-limit readings and what was done:', 'fastnutrition-mealprep' ) . '</div><div class="cpr-lines"><div></div><div></div></div>';
		}
		echo '<div class="cpr-legend">' . esc_html__( 'Batch codes are printed beside each meal on the pick list, so any order traces back to this sheet.', 'fastnutrition-mealprep' ) . '</div>';
		if ( $contains_sample ) {
			echo '<div class="cpr-specimen">' . esc_html__( 'Specimen — contains sample readings generated for demonstration, not a food-safety record.', 'fastnutrition-mealprep' ) . '</div>';
		}
		echo '</div></div>';
	}

	private static function tick( bool $on ): string {
		return $on ? '<span class="cpr-box cpr-box-on">✓</span>' : '<span class="cpr-box"></span>';
	}

	public static function fmt_num( $v ): string {
		if ( '' === $v || null === $v || ! is_numeric( $v ) ) {
			return "\u{00A0}";
		}
		$f = (float) $v;
		return floor( $f ) === $f ? number_format( $f, 0 ) : number_format( $f, 1 );
	}

	public static function fmt_date( string $ymd ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return "\u{00A0}";
		}
		$ts = strtotime( $ymd . ' 12:00:00' );
		// "25 Nov" rather than "Wed 25 Nov": the longer form wrapped the use-by
		// column onto two lines in the PDF and cost a page on a busy day.
		return false !== $ts ? wp_date( 'j M', $ts ) : $ymd;
	}

	/**
	 * Shared stylesheet. Dompdf ignores box-sizing, so widths are content
	 * widths; A4 landscape at 10 mm margins gives a 277 mm column.
	 */
	public static function print_styles( bool $for_pdf ): void {
		?>
		<style>
		<?php if ( $for_pdf ) : ?>
		@page { size: A4 landscape; margin: 0; }
		html { margin: 0; padding: 0; }
		body { margin: 10mm; padding: 0; font-family: DejaVu Sans, sans-serif; color: #000; font-size: 8.5pt; line-height: 11pt; }
		<?php else : ?>
		.fn-cpr-paper { background: #fff; border: 1px solid #c3c4c7; padding: 10mm; max-width: 277mm; overflow-x: auto; font-size: 11px; line-height: 1.35; color: #000; }
		@media print {
			@page { size: A4 landscape; margin: 10mm; }
			.fn-no-print, #adminmenumain, #wpadminbar, #wpfooter, .notice { display: none !important; }
			#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
			.fn-cpr-paper { border: 0; padding: 0; max-width: none; overflow: visible; font-size: 8.5pt; }
		}
		<?php endif; ?>
		.cpr table { border-collapse: collapse; }
		.cpr-head { width: 100%; margin-bottom: 2mm; }
		.cpr-head td { vertical-align: top; padding: 0; }
		.cpr-head-r { text-align: right; }
		.cpr-title { font-size: 15pt; font-weight: bold; letter-spacing: 0.5mm; }
		.cpr-sub { font-size: 9.5pt; margin-top: 1mm; }
		.cpr-sign { font-size: 9pt; font-weight: bold; }
		.cpr-limits { font-size: 8pt; font-style: italic; margin-top: 1mm; }
		.cpr-h { font-weight: bold; text-transform: uppercase; font-size: 7.5pt; letter-spacing: 0.3mm; margin-bottom: 0.8mm; }
		.cpr-checks { width: 100%; border: 0.6pt solid #000; margin-bottom: 2.5mm; }
		.cpr-checks td { vertical-align: top; padding: 1.5mm 2.5mm; }
		.cpr-checks-units { width: 48%; border-right: 0.6pt solid #000; }
		.cpr-units th, .cpr-units td { padding: 0.4mm 2mm 0.4mm 0; text-align: left; font-size: 8pt; }
		.cpr-blank { display: inline-block; border-bottom: 0.6pt solid #000; min-height: 3.5mm; text-align: center; padding: 0 0.5mm; }
		.cpr-time { min-width: 11mm; }
		.cpr-temp { min-width: 9mm; }
		.cpr-date { min-width: 15mm; }
		.cpr-init { min-width: 8mm; }
		.cpr-unit { min-width: 20mm; }
		.cpr-box { display: inline-block; width: 3.2mm; height: 3.2mm; border: 0.6pt solid #000; vertical-align: middle; text-align: center; font-size: 7pt; line-height: 3.2mm; }
		.cpr-box-on { font-weight: bold; }
		.cpr-notes { margin-top: 1.5mm; font-style: italic; }
		.cpr-batches { width: 100%; }
		.cpr-batches thead th { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.2mm; text-align: left; border-bottom: 1.2pt solid #000; padding: 1mm 1.2mm; }
		.cpr-batches tbody td { padding: 1.2mm 1.2mm; border-bottom: 0.4pt solid #000; vertical-align: top; font-size: 8pt; }
		.cpr-batches tbody tr { page-break-inside: avoid; }
		.cpr-batches .num { text-align: right; }
		.cpr-batches .c0 { width: 16mm; font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; }
		.cpr-batches .c1 { width: 42mm; }
		.cpr-batches .c2 { width: 16mm; }
		.cpr-batches .c3 { width: 12mm; }
		.cpr-batches .c4 { width: 13mm; }
		.cpr-batches .c5, .cpr-batches .c6, .cpr-batches .c11 { width: 14mm; }
		.cpr-batches .c7, .cpr-batches .c8 { width: 12mm; }
		.cpr-batches .c9 { width: 15mm; }
		.cpr-batches .c10 { width: 24mm; }
		.cpr-batches .c12 { width: 17mm; }
		.cpr-batches .c13 { width: 10mm; }
		.cpr-typical { font-size: 7pt; font-style: italic; }
		.cpr-nocook { font-style: italic; }
		.cpr-na { color: #000; }
		.cpr-flag td { background: #e8e8e8; }
		.cpr-action { font-size: 7.5pt; }
		.cpr-foot { margin-top: 3mm; }
		.cpr-lines div { border-bottom: 0.5pt solid #000; height: 5.5mm; }
		.cpr-legend { font-size: 7.5pt; font-style: italic; margin-top: 2mm; }
		.cpr-specimen { font-size: 7.5pt; margin-top: 1.5mm; border-top: 0.5pt solid #000; padding-top: 1mm; }
		</style>
		<?php
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

use FastNutrition\MealPrep\Cart\Selection;
use FastNutrition\MealPrep\PostTypes\Ingredient;

/**
 * Records Archive: search saved prep records by date, component, batch or
 * order (traceability), export them as CSV for the EHO, see a monthly summary
 * of out-of-limit events, and manage demonstration data (seed / delete sample
 * records).
 */
final class RecordsArchive {

	public const PAGE_SLUG = 'fn-records-archive';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Filters -------------------------------------------------------------------

	/**
	 * Current filters from the query string.
	 *
	 * @return array<string,mixed>
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$get  = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : '';
		$date = static fn( string $v ): string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$f    = [
			'from'            => $date( $get( 'from' ) ),
			'to'              => $date( $get( 'to' ) ),
			'ingredient_id'   => (int) $get( 'ingredient_id' ),
			'batch'           => $get( 'batch' ),
			'order_id'        => (int) $get( 'order_id' ),
			'out_only'        => '' !== $get( 'out_only' ),
			'include_samples' => ! isset( $_GET['from'] ) || '' !== $get( 'samples' ),
		];
		// phpcs:enable
		if ( '' === $f['from'] && '' === $f['to'] && 0 === $f['order_id'] && '' === $f['batch'] ) {
			$f['from'] = gmdate( 'Y-m-d', (int) strtotime( '-30 days' ) );
			$f['to']   = wp_date( 'Y-m-d' );
		}
		return $f;
	}

	/**
	 * Resolve the order filter to a date and the component ids in that order.
	 *
	 * @return array{date:string,ingredient_ids:int[],error:string}
	 */
	private static function order_scope( int $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return [ 'date' => '', 'ingredient_ids' => [], 'error' => __( 'Order not found.', 'fastnutrition-mealprep' ) ];
		}
		$ff   = $order->get_meta( '_fn_fulfilment' );
		$date = is_array( $ff ) ? (string) ( $ff['date'] ?? '' ) : '';
		$ids  = [];
		foreach ( $order->get_items() as $item ) {
			$sel = $item->get_meta( '_fn_selection', true );
			if ( is_array( $sel ) ) {
				$ids = array_merge( $ids, Selection::ingredient_ids( $sel ) );
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( '' === $date ) {
			return [ 'date' => '', 'ingredient_ids' => $ids, 'error' => __( 'That order has no fulfilment date, so it cannot be traced to a prep day.', 'fastnutrition-mealprep' ) ];
		}
		return [ 'date' => $date, 'ingredient_ids' => $ids, 'error' => '' ];
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array{rows:array<int,array<string,mixed>>,error:string}
	 */
	private static function run( array $f ): array {
		$q = $f;
		if ( ! empty( $f['order_id'] ) ) {
			$scope = self::order_scope( (int) $f['order_id'] );
			if ( '' !== $scope['error'] ) {
				return [ 'rows' => [], 'error' => $scope['error'] ];
			}
			$q['from']           = $scope['date'];
			$q['to']             = $scope['date'];
			$q['ingredient_ids'] = $scope['ingredient_ids'];
			if ( empty( $scope['ingredient_ids'] ) ) {
				return [ 'rows' => [], 'error' => __( 'That order has no meal components to trace.', 'fastnutrition-mealprep' ) ];
			}
		}
		return [ 'rows' => PrepRecordsRepository::query( $q ), 'error' => '' ];
	}

	// --- Actions: CSV, seed, delete samples -----------------------------------------

	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['action'] ) && 'csv' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'fn_records_csv' );
			$this->stream_csv();
			exit;
		}

		if ( isset( $_POST['fn_archive_action'], $_POST['fn_archive_nonce'] ) ) {
			check_admin_referer( 'fn_records_demo', 'fn_archive_nonce' );
			$action = sanitize_key( wp_unslash( (string) $_POST['fn_archive_action'] ) );
			$notice = '';
			if ( 'seed' === $action ) {
				$from = isset( $_POST['seed_from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seed_from'] ) ) : '';
				$to   = isset( $_POST['seed_to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seed_to'] ) ) : '';
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
					$r      = PrepRecordsRepository::seed_samples( $from, $to, get_current_user_id() );
					$notice = 'seeded&days=' . (int) $r['days'] . '&rows=' . (int) $r['rows'];
				}
			} elseif ( 'delete_samples' === $action ) {
				$n      = PrepRecordsRepository::delete_samples();
				$notice = 'samples_deleted&rows=' . (int) $n;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . ( '' !== $notice ? '&notice=' . $notice : '' ) ) );
			exit;
		}
	}

	private function stream_csv(): void {
		$f      = $this->filters();
		$result = self::run( $f );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="prep-records-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			return;
		}
		fputcsv( $out, [ 'Date', 'Batch', 'Component', 'Method', 'Portions', 'Target C', 'Time in', 'Time out', 'Core 1 C', 'Core 2 C', 'Cooled by', 'Fridge', 'Packed', 'Use by', 'Initials', 'Out of limit', 'Action taken', 'Sample', 'Recorded at' ] );
		foreach ( $result['rows'] as $r ) {
			fputcsv(
				$out,
				[
					$r['record_date'],
					$r['batch_code'],
					$r['component'],
					FoodSafetySettings::method_label( (string) $r['method'] ),
					$r['portions'],
					null === $r['target_c'] ? '' : $r['target_c'],
					$r['time_in'],
					$r['time_out'],
					$r['probe1_c'],
					$r['probe2_c'],
					$r['cooled_by'],
					$r['fridge_unit'],
					$r['packed_at'],
					$r['use_by'],
					$r['initials'],
					$r['out_of_limit'] ? 'yes' : 'no',
					$r['corrective_action'],
					$r['is_sample'] ? 'yes' : 'no',
					$r['updated_at'],
				]
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	// --- Render ----------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}
		$f      = $this->filters();
		$result = self::run( $f );
		$rows   = $result['rows'];
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'Records Archive', 'fastnutrition-mealprep' ) . '</h1>';
		if ( 'seeded' === $notice ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 0;
			$n    = isset( $_GET['rows'] ) ? (int) $_GET['rows'] : 0;
			// phpcs:enable
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: 1: days, 2: rows */ __( 'Sample records seeded for %1$d prep days (%2$d batch rows). Days with no orders were skipped.', 'fastnutrition-mealprep' ), $days, $n ) ) . '</p></div>';
		} elseif ( 'samples_deleted' === $notice ) {
			$n = isset( $_GET['rows'] ) ? (int) $_GET['rows'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: rows */ __( '%d sample records deleted.', 'fastnutrition-mealprep' ), $n ) ) . '</p></div>';
		}
		if ( '' !== $result['error'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $result['error'] ) . '</p></div>';
		}

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:12px 0;max-width:1100px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page is for', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Every batch saved in Prep Records. Filter by date, component or batch code, or type an order number to see the cook records for the components in that order. Export the result as CSV for the Environmental Health Officer, or open a day to print its Cook & Pack Record with the saved readings.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		// Filters.
		echo '<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		printf( '<label>%s<br><input type="date" name="from" value="%s" /></label>', esc_html__( 'From', 'fastnutrition-mealprep' ), esc_attr( (string) $f['from'] ) );
		printf( '<label>%s<br><input type="date" name="to" value="%s" /></label>', esc_html__( 'To', 'fastnutrition-mealprep' ), esc_attr( (string) $f['to'] ) );
		echo '<label>' . esc_html__( 'Component', 'fastnutrition-mealprep' ) . '<br><select name="ingredient_id"><option value="">' . esc_html__( 'All', 'fastnutrition-mealprep' ) . '</option>';
		foreach ( self::ingredients() as $id => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $id, selected( (int) $f['ingredient_id'], (int) $id, false ), esc_html( $name ) );
		}
		echo '</select></label>';
		printf( '<label>%s<br><input type="text" name="batch" value="%s" style="width:120px" placeholder="CHC" /></label>', esc_html__( 'Batch code', 'fastnutrition-mealprep' ), esc_attr( (string) $f['batch'] ) );
		printf( '<label>%s<br><input type="number" name="order_id" value="%s" style="width:110px" /></label>', esc_html__( 'Order #', 'fastnutrition-mealprep' ), esc_attr( $f['order_id'] ? (string) $f['order_id'] : '' ) );
		printf( '<label style="padding-bottom:6px"><input type="checkbox" name="out_only" value="1" %s /> %s</label>', checked( $f['out_only'], true, false ), esc_html__( 'Out of limit only', 'fastnutrition-mealprep' ) );
		printf( '<label style="padding-bottom:6px"><input type="checkbox" name="samples" value="1" %s /> %s</label>', checked( $f['include_samples'], true, false ), esc_html__( 'Include sample records', 'fastnutrition-mealprep' ) );
		submit_button( __( 'Filter', 'fastnutrition-mealprep' ), 'primary', '', false );
		$csv = wp_nonce_url( add_query_arg( array_merge( $_GET, [ 'action' => 'csv' ] ), admin_url( 'admin.php' ) ), 'fn_records_csv' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- current filters re-encoded by add_query_arg; nothing is trusted on the other side.
		echo ' <a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Export CSV', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form>';

		// Results.
		echo '<p>' . esc_html( sprintf( /* translators: %d: rows */ _n( '%d record', '%d records', count( $rows ), 'fastnutrition-mealprep' ), count( $rows ) ) ) . ( count( $rows ) >= 500 ? ' ' . esc_html__( '(showing the first 500 — narrow the dates to see the rest)', 'fastnutrition-mealprep' ) : '' ) . '</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped" style="min-width:1100px"><thead><tr>';
		foreach ( [ __( 'Date', 'fastnutrition-mealprep' ), __( 'Batch', 'fastnutrition-mealprep' ), __( 'Component', 'fastnutrition-mealprep' ), __( 'Method', 'fastnutrition-mealprep' ), __( 'Portions', 'fastnutrition-mealprep' ), __( 'Target', 'fastnutrition-mealprep' ), __( 'In', 'fastnutrition-mealprep' ), __( 'Out', 'fastnutrition-mealprep' ), __( 'Core 1', 'fastnutrition-mealprep' ), __( 'Core 2', 'fastnutrition-mealprep' ), __( 'Cooled by', 'fastnutrition-mealprep' ), __( 'Fridge', 'fastnutrition-mealprep' ), __( 'Packed', 'fastnutrition-mealprep' ), __( 'Use-by', 'fastnutrition-mealprep' ), __( 'Init.', 'fastnutrition-mealprep' ), __( 'Flags', 'fastnutrition-mealprep' ), '' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="17"><em>' . esc_html__( 'No records match.', 'fastnutrition-mealprep' ) . '</em></td></tr>';
		}
		foreach ( $rows as $r ) {
			$flags = [];
			if ( $r['out_of_limit'] ) {
				$flags[] = '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'Out of limit', 'fastnutrition-mealprep' ) . '</span>';
			}
			if ( $r['is_sample'] ) {
				$flags[] = '<span style="background:#dba617;color:#1d2327;font-size:10px;padding:1px 6px;border-radius:3px;font-weight:600">' . esc_html__( 'Sample', 'fastnutrition-mealprep' ) . '</span>';
			}
			$day_url   = admin_url( 'admin.php?page=' . PrepRecordsPage::PAGE_SLUG . '&date=' . rawurlencode( (string) $r['record_date'] ) );
			$print_url = CookPackRecord::pdf_url( (string) $r['record_date'], false );
			printf(
				'<tr><td>%s</td><td style="font-family:monospace">%s</td><td><strong>%s</strong>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> · <a href="%s">%s</a></td></tr>',
				esc_html( (string) $r['record_date'] ),
				esc_html( (string) $r['batch_code'] ),
				esc_html( (string) $r['component'] ),
				'' !== (string) $r['corrective_action'] ? '<br><small>' . esc_html( (string) $r['corrective_action'] ) . '</small>' : '',
				esc_html( FoodSafetySettings::method_label( (string) $r['method'] ) ),
				(int) $r['portions'],
				esc_html( null === $r['target_c'] ? '—' : CookPackRecord::fmt_num( $r['target_c'] ) ),
				esc_html( (string) $r['time_in'] ),
				esc_html( (string) $r['time_out'] ),
				esc_html( CookPackRecord::fmt_num( $r['probe1_c'] ) ),
				esc_html( CookPackRecord::fmt_num( $r['probe2_c'] ) ),
				esc_html( (string) $r['cooled_by'] ),
				esc_html( (string) $r['fridge_unit'] ),
				esc_html( (string) $r['packed_at'] ),
				esc_html( (string) $r['use_by'] ),
				esc_html( (string) $r['initials'] ),
				implode( ' ', $flags ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_url( $day_url ),
				esc_html__( 'Open day', 'fastnutrition-mealprep' ),
				esc_url( $print_url ),
				esc_html__( 'Print day', 'fastnutrition-mealprep' )
			);
		}
		echo '</tbody></table></div>';

		// Monthly summary.
		$summary = PrepRecordsRepository::monthly_summary( (bool) $f['include_samples'] );
		echo '<h2 style="margin-top:28px">' . esc_html__( 'Monthly summary', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>' . esc_html__( 'Month', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Days with records', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Days with daily checks', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Batches', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Out of limit', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Sample rows', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
		if ( empty( $summary ) ) {
			echo '<tr><td colspan="6"><em>' . esc_html__( 'No records yet.', 'fastnutrition-mealprep' ) . '</em></td></tr>';
		}
		foreach ( $summary as $m ) {
			$ts = strtotime( $m['month'] . '-01 12:00:00' );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%d</td></tr>',
				esc_html( false !== $ts ? wp_date( 'F Y', $ts ) : $m['month'] ),
				(int) $m['days'],
				(int) $m['check_days'],
				(int) $m['batches'],
				$m['out_of_limit'] > 0 ? '<span style="color:#b32d2e;font-weight:600">' . (int) $m['out_of_limit'] . '</span>' : '0', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $m['samples']
			);
		}
		echo '</tbody></table>';

		// Demo tools.
		$sample_count = PrepRecordsRepository::count_samples();
		echo '<h2 style="margin-top:28px">' . esc_html__( 'Demonstration data', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description" style="max-width:800px">' . esc_html__( 'Seed believable sample records for a date range so the archive, the Prep Records screen and the printed sheets can be shown filled in. Values come from the pools in Food Safety Settings and are the same every time for a given day. Sample rows are flagged, never overwrite a real record, and can be removed in one click.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:10px">';
		wp_nonce_field( 'fn_records_demo', 'fn_archive_nonce' );
		echo '<input type="hidden" name="fn_archive_action" value="seed" />';
		printf( '<label>%s<br><input type="date" name="seed_from" value="%s" /></label>', esc_html__( 'From', 'fastnutrition-mealprep' ), esc_attr( gmdate( 'Y-m-d', (int) strtotime( '-14 days' ) ) ) );
		printf( '<label>%s<br><input type="date" name="seed_to" value="%s" /></label>', esc_html__( 'To', 'fastnutrition-mealprep' ), esc_attr( wp_date( 'Y-m-d' ) ) );
		echo '<button class="button">' . esc_html__( 'Seed sample records', 'fastnutrition-mealprep' ) . '</button>';
		echo '</form>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_records_demo', 'fn_archive_nonce' );
		echo '<input type="hidden" name="fn_archive_action" value="delete_samples" />';
		printf( '<button class="button" %s>%s</button>', $sample_count > 0 ? '' : 'disabled', esc_html( sprintf( /* translators: %d: rows */ __( 'Delete all sample records (%d)', 'fastnutrition-mealprep' ), $sample_count ) ) );
		echo '</form>';
		echo '</div>';
	}

	/** @return array<int,string> ingredient id => title */
	private static function ingredients(): array {
		$posts = get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		$out   = [];
		foreach ( $posts as $p ) {
			$out[ (int) $p->ID ] = (string) $p->post_title;
		}
		return $out;
	}
}

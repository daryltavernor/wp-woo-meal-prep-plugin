<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Stats\StatsRollup;

/**
 * Fulfilment Reports page.
 *
 * Reads the permanent rollup ledger (fn_daily_stats / fn_daily_ingredient_stats
 * / fn_daily_meal_stats) — never order history — so any period can be summed and
 * compared instantly. Supports a date range, day/week/month granularity, an
 * optional compare period with deltas, CSV export, and a manual range rebuild.
 */
final class Reports {

	public const PAGE_SLUG = 'fn-reports';

	public function register(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_handle_actions' ] );
	}

	/** CSV export + manual rebuild, handled before any page output. */
	public static function maybe_handle_actions(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Manual rebuild of the visible range.
		if ( isset( $_POST['fn_rebuild_range'] ) && check_admin_referer( 'fn_reports_rebuild' ) ) {
			[ $from, $to ] = self::range_from_request( 'from', 'to', '-29 days', 'now', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::queue_rebuild( $from, $to );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'from' => $from, 'to' => $to, 'fn_rebuilt' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// CSV export of the time series.
		if ( isset( $_GET['fn_export'] ) && 'csv' === $_GET['fn_export'] && check_admin_referer( 'fn_reports_export' ) ) {
			[ $from, $to ] = self::range_from_request( 'from', 'to' );
			$gran = self::granularity();
			self::stream_csv( $from, $to, $gran );
			exit;
		}
	}

	public static function render_static(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		[ $from, $to ] = self::range_from_request( 'from', 'to' );
		$gran          = self::granularity();
		$compare       = isset( $_GET['compare'] ) && '1' === $_GET['compare']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		[ $cfrom, $cto ] = self::range_from_request( 'cfrom', 'cto', '-60 days', '-31 days' );

		$totals  = self::totals( $from, $to );
		$methods = self::by_method( $from, $to );
		$series  = self::series( $from, $to, $gran );
		$ctotals = $compare ? self::totals( $cfrom, $cto ) : null;

		echo '<div class="wrap fn-reports">';
		echo '<h1>' . esc_html__( 'Fulfilment Reports', 'fastnutrition-mealprep' ) . '</h1>';

		if ( isset( $_GET['fn_rebuilt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rebuild queued for the selected range. Figures refresh as the background job runs.', 'fastnutrition-mealprep' ) . '</p></div>';
		}

		self::render_filters( $from, $to, $gran, $compare, $cfrom, $cto );
		self::render_presets();

		// Summary cards (period A, with deltas vs compare period when on).
		echo '<h2>' . esc_html( sprintf( __( 'Summary — %1$s to %2$s', 'fastnutrition-mealprep' ), $from, $to ) ) . '</h2>';
		if ( $compare ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Compared with %1$s to %2$s', 'fastnutrition-mealprep' ), $cfrom, $cto ) ) . '</p>';
		}
		self::render_cards( $totals, $ctotals );

		// Delivery vs collection split.
		self::render_methods( $methods );

		// Time series.
		self::render_series( $series, $gran, $from, $to );

		// Popularity.
		self::render_top_ingredients( self::top_ingredients( $from, $to, 25 ) );
		self::render_top_meals( self::top_meals( $from, $to, 25 ) );

		echo '</div>';
	}

	// --------------------------------------------------------------- rendering

	private static function render_filters( string $from, string $to, string $gran, bool $compare, string $cfrom, string $cto ): void {
		echo '<form method="get" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		printf( ' <label>%s <input type="date" name="from" value="%s" /></label> ', esc_html__( 'From', 'fastnutrition-mealprep' ), esc_attr( $from ) );
		printf( ' <label>%s <input type="date" name="to" value="%s" /></label> ', esc_html__( 'To', 'fastnutrition-mealprep' ), esc_attr( $to ) );

		echo ' <label>' . esc_html__( 'Group by', 'fastnutrition-mealprep' ) . ' <select name="gran">';
		foreach ( [ 'day' => __( 'Day', 'fastnutrition-mealprep' ), 'week' => __( 'Week', 'fastnutrition-mealprep' ), 'month' => __( 'Month', 'fastnutrition-mealprep' ) ] as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $gran, $k, false ), esc_html( $label ) );
		}
		echo '</select></label> ';

		printf(
			' <label><input type="checkbox" name="compare" value="1"%s /> %s</label> ',
			checked( $compare, true, false ),
			esc_html__( 'Compare with', 'fastnutrition-mealprep' )
		);
		printf( ' <input type="date" name="cfrom" value="%s" /> ', esc_attr( $cfrom ) );
		printf( ' <input type="date" name="cto" value="%s" /> ', esc_attr( $cto ) );

		echo ' <button type="submit" class="button button-primary">' . esc_html__( 'Apply', 'fastnutrition-mealprep' ) . '</button>';

		// Export + rebuild.
		$export_url = wp_nonce_url(
			add_query_arg( [ 'page' => self::PAGE_SLUG, 'from' => $from, 'to' => $to, 'gran' => $gran, 'fn_export' => 'csv' ], admin_url( 'admin.php' ) ),
			'fn_reports_export'
		);
		echo ' <a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form>';

		echo '<form method="post" style="margin:-6px 0 12px;">';
		echo '<input type="hidden" name="from" value="' . esc_attr( $from ) . '" />';
		echo '<input type="hidden" name="to" value="' . esc_attr( $to ) . '" />';
		wp_nonce_field( 'fn_reports_rebuild' );
		echo '<button type="submit" name="fn_rebuild_range" value="1" class="button button-secondary">' . esc_html__( 'Rebuild this range', 'fastnutrition-mealprep' ) . '</button>';
		echo ' <span class="description">' . esc_html__( 'Recompute the figures for the selected dates from current orders.', 'fastnutrition-mealprep' ) . '</span>';
		echo '</form>';
	}

	private static function render_presets(): void {
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'now', $tz );
		$presets = [
			__( 'Last 30 days', 'fastnutrition-mealprep' )  => [ $today->modify( '-29 days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) ],
			__( 'This month', 'fastnutrition-mealprep' )     => [ $today->format( 'Y-m-01' ), $today->format( 'Y-m-d' ) ],
			__( 'Last month', 'fastnutrition-mealprep' )     => [ $today->modify( 'first day of last month' )->format( 'Y-m-d' ), $today->modify( 'last day of last month' )->format( 'Y-m-d' ) ],
			__( 'This year', 'fastnutrition-mealprep' )      => [ $today->format( 'Y-01-01' ), $today->format( 'Y-m-d' ) ],
		];
		echo '<p>';
		foreach ( $presets as $label => $range ) {
			$url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'from' => $range[0], 'to' => $range[1] ], admin_url( 'admin.php' ) );
			echo '<a class="button button-small" style="margin-right:4px;" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</p>';
	}

	private static function render_cards( array $t, ?array $c ): void {
		$orders = max( 1, (int) $t['orders'] );
		$cards  = [
			__( 'Total meals', 'fastnutrition-mealprep' )      => [ 'val' => (int) $t['meals'], 'cmp' => $c ? (int) $c['meals'] : null ],
			__( 'Items to make', 'fastnutrition-mealprep' )    => [ 'val' => (int) $t['items'], 'cmp' => $c ? (int) $c['items'] : null ],
			__( 'Orders', 'fastnutrition-mealprep' )           => [ 'val' => (int) $t['orders'], 'cmp' => $c ? (int) $c['orders'] : null ],
			__( 'Revenue', 'fastnutrition-mealprep' )          => [ 'val' => (float) $t['revenue'], 'cmp' => $c ? (float) $c['revenue'] : null, 'money' => true ],
			__( 'Sweets', 'fastnutrition-mealprep' )           => [ 'val' => (int) $t['sweets'], 'cmp' => $c ? (int) $c['sweets'] : null ],
			__( 'Add-ons', 'fastnutrition-mealprep' )          => [ 'val' => (int) $t['addons'], 'cmp' => $c ? (int) $c['addons'] : null ],
			__( 'Avg meals / order', 'fastnutrition-mealprep' ) => [ 'val' => round( $t['meals'] / $orders, 1 ), 'cmp' => null ],
			__( 'Avg order value', 'fastnutrition-mealprep' )  => [ 'val' => round( $t['revenue'] / $orders, 2 ), 'cmp' => null, 'money' => true ],
		];

		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:10px 0;">';
		foreach ( $cards as $label => $card ) {
			$display = ! empty( $card['money'] ) ? wp_kses_post( wc_price( (float) $card['val'] ) ) : esc_html( (string) $card['val'] );
			echo '<div style="flex:1 1 180px;min-width:160px;padding:12px 14px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;">';
			echo '<div style="color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.03em;">' . esc_html( $label ) . '</div>';
			echo '<div style="font-size:24px;font-weight:700;line-height:1.3;">' . $display . '</div>';
			if ( null !== $card['cmp'] ) {
				echo self::delta_html( (float) $card['val'], (float) $card['cmp'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_methods( array $methods ): void {
		echo '<h2>' . esc_html__( 'Delivery vs Collection', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Orders', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Revenue', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		$labels = [ 'delivery' => __( 'Delivery', 'fastnutrition-mealprep' ), 'collection' => __( 'Collection', 'fastnutrition-mealprep' ) ];
		foreach ( $labels as $key => $label ) {
			$row = $methods[ $key ] ?? [ 'orders' => 0, 'meals' => 0, 'revenue' => 0 ];
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			echo '<td>' . (int) $row['orders'] . '</td>';
			echo '<td>' . (int) $row['meals'] . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $row['revenue'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_series( array $series, string $gran, string $from, string $to ): void {
		$heads = [ 'day' => __( 'Day', 'fastnutrition-mealprep' ), 'week' => __( 'Week', 'fastnutrition-mealprep' ), 'month' => __( 'Month', 'fastnutrition-mealprep' ) ];
		echo '<h2>' . esc_html__( 'Over time', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $series ) ) {
			echo '<p><em>' . esc_html__( 'No data in this range yet.', 'fastnutrition-mealprep' ) . '</em></p>';
			return;
		}
		$max = 1;
		foreach ( $series as $r ) {
			$max = max( $max, (int) $r['meals'] );
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html( $heads[ $gran ] ?? '' ) . '</th>';
		echo '<th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Deliveries', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Collections', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Revenue', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:30%;">' . esc_html__( 'Meals trend', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $series as $r ) {
			$pct = (int) round( ( (int) $r['meals'] ) / $max * 100 );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['bucket'] ) . '</td>';
			echo '<td>' . (int) $r['meals'] . '</td>';
			echo '<td>' . (int) $r['deliveries'] . '</td>';
			echo '<td>' . (int) $r['collections'] . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $r['revenue'] ) ) . '</td>';
			echo '<td><div style="background:#2271b1;height:14px;width:' . esc_attr( (string) $pct ) . '%;min-width:1px;"></div></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_top_ingredients( array $rows ): void {
		echo '<h2>' . esc_html__( 'Most used ingredients', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No data in this range yet.', 'fastnutrition-mealprep' ) . '</em></p>';
			return;
		}
		$ids   = array_map( static fn( $r ) => (int) $r['ingredient_id'], $rows );
		$types = Ingredient::get_type_slugs_for( $ids );
		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Ingredient', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Portions', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id    = (int) $r['ingredient_id'];
			$title = get_the_title( $id );
			echo '<tr><td>' . esc_html( '' !== $title ? $title : '#' . $id ) . '</td>';
			echo '<td>' . esc_html( (string) ( $types[ $id ] ?? '' ) ) . '</td>';
			echo '<td>' . (int) $r['portions'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_top_meals( array $rows ): void {
		echo '<h2>' . esc_html__( 'Most popular meals', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No data in this range yet.', 'fastnutrition-mealprep' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Meal', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Quantity', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( self::meal_label( (string) $r['meal_key'], (string) $r['mode'] ) ) . '</td>';
			echo '<td>' . esc_html( self::mode_label( (string) $r['mode'] ) ) . '</td>';
			echo '<td>' . (int) $r['qty'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function delta_html( float $now, float $prev ): string {
		if ( $prev <= 0.0 ) {
			return '<div style="font-size:12px;color:#787c82;">' . esc_html__( 'no prior data', 'fastnutrition-mealprep' ) . '</div>';
		}
		$pct   = ( $now - $prev ) / $prev * 100;
		$up    = $pct >= 0;
		$color = $up ? '#008a20' : '#d63638';
		$arrow = $up ? '▲' : '▼';
		return '<div style="font-size:12px;color:' . esc_attr( $color ) . ';">' . esc_html( $arrow . ' ' . number_format( abs( $pct ), 1 ) . '%' ) . '</div>';
	}

	// ------------------------------------------------------------------ queries

	/** @return array{orders:int,meals:int,sweets:int,addons:int,items:int,revenue:float} */
	private static function totals( string $from, string $to ): array {
		global $wpdb;
		$table = StatsRollup::stats_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(orders),0) orders, COALESCE(SUM(meals),0) meals, COALESCE(SUM(sweets),0) sweets, COALESCE(SUM(addons),0) addons, COALESCE(SUM(items_total),0) items, COALESCE(SUM(revenue),0) revenue FROM {$table} WHERE stat_date BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);
		return [
			'orders'  => (int) ( $row['orders'] ?? 0 ),
			'meals'   => (int) ( $row['meals'] ?? 0 ),
			'sweets'  => (int) ( $row['sweets'] ?? 0 ),
			'addons'  => (int) ( $row['addons'] ?? 0 ),
			'items'   => (int) ( $row['items'] ?? 0 ),
			'revenue' => (float) ( $row['revenue'] ?? 0 ),
		];
	}

	/** @return array<string,array{orders:int,meals:int,revenue:float}> */
	private static function by_method( string $from, string $to ): array {
		global $wpdb;
		$table = StatsRollup::stats_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method, SUM(orders) orders, SUM(meals) meals, SUM(revenue) revenue FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY method", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['method'] ] = [ 'orders' => (int) $r['orders'], 'meals' => (int) $r['meals'], 'revenue' => (float) $r['revenue'] ];
		}
		return $out;
	}

	/** @return array<int,array{bucket:string,meals:int,deliveries:int,collections:int,revenue:float}> */
	private static function series( string $from, string $to, string $gran ): array {
		global $wpdb;
		$table  = StatsRollup::stats_table();
		$bucket = self::bucket_expr( $gran );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket,
					SUM(meals) meals,
					SUM(CASE WHEN method='delivery' THEN orders ELSE 0 END) deliveries,
					SUM(CASE WHEN method='collection' THEN orders ELSE 0 END) collections,
					SUM(revenue) revenue
				 FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY bucket ORDER BY bucket ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'bucket'      => (string) $r['bucket'],
				'meals'       => (int) $r['meals'],
				'deliveries'  => (int) $r['deliveries'],
				'collections' => (int) $r['collections'],
				'revenue'     => (float) $r['revenue'],
			];
		}
		return $out;
	}

	/** @return array<int,array{ingredient_id:int,portions:int}> */
	private static function top_ingredients( string $from, string $to, int $limit ): array {
		global $wpdb;
		$table = StatsRollup::ingredient_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ingredient_id, SUM(portions) portions FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY ingredient_id ORDER BY portions DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to,
				$limit
			),
			ARRAY_A
		);
		return array_map( static fn( $r ) => [ 'ingredient_id' => (int) $r['ingredient_id'], 'portions' => (int) $r['portions'] ], (array) $rows );
	}

	/** @return array<int,array{meal_key:string,mode:string,qty:int}> */
	private static function top_meals( string $from, string $to, int $limit ): array {
		global $wpdb;
		$table = StatsRollup::meal_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meal_key, mode, SUM(qty) qty FROM {$table} WHERE stat_date BETWEEN %s AND %s GROUP BY meal_key, mode ORDER BY qty DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to,
				$limit
			),
			ARRAY_A
		);
		return array_map( static fn( $r ) => [ 'meal_key' => (string) $r['meal_key'], 'mode' => (string) $r['mode'], 'qty' => (int) $r['qty'] ], (array) $rows );
	}

	private static function bucket_expr( string $gran ): string {
		switch ( $gran ) {
			case 'month':
				return "DATE_FORMAT(stat_date,'%Y-%m')";
			case 'week':
				return "DATE_FORMAT(stat_date,'%x-W%v')";
			case 'day':
			default:
				return 'stat_date';
		}
	}

	// ------------------------------------------------------------------ helpers

	/** @return array{0:string,1:string} from/to, validated, defaulting to a recent window. */
	private static function range_from_request( string $from_key, string $to_key, string $def_from = '-29 days', string $def_to = 'now', ?array $source = null ): array {
		$tz     = wp_timezone();
		$today  = new \DateTimeImmutable( 'now', $tz );
		$source = null !== $source ? $source : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from   = isset( $source[ $from_key ] ) ? sanitize_text_field( wp_unslash( (string) $source[ $from_key ] ) ) : '';
		$to     = isset( $source[ $to_key ] ) ? sanitize_text_field( wp_unslash( (string) $source[ $to_key ] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = $today->modify( $def_from )->format( 'Y-m-d' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = $today->modify( $def_to )->format( 'Y-m-d' );
		}
		if ( $from > $to ) {
			[ $from, $to ] = [ $to, $from ];
		}
		return [ $from, $to ];
	}

	private static function granularity(): string {
		$g = isset( $_GET['gran'] ) ? sanitize_key( (string) $_GET['gran'] ) : 'day'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $g, [ 'day', 'week', 'month' ], true ) ? $g : 'day';
	}

	private static function mode_label( string $mode ): string {
		$labels = [
			'build'      => __( 'Build', 'fastnutrition-mealprep' ),
			'set'        => __( 'Set meal', 'fastnutrition-mealprep' ),
			'standalone' => __( 'Standalone', 'fastnutrition-mealprep' ),
		];
		return $labels[ $mode ] ?? $mode;
	}

	/** Resolve a stored meal key ('p:ID' or a build combo signature) to a human label. */
	private static function meal_label( string $key, string $mode ): string {
		if ( str_starts_with( $key, 'p:' ) ) {
			$pid   = (int) substr( $key, 2 );
			$title = get_the_title( $pid );
			return '' !== $title ? $title : '#' . $pid;
		}
		$parts = explode( ':', $key );
		if ( count( $parts ) >= 4 && 'b' === $parts[0] ) {
			$names = [];
			foreach ( [ (int) $parts[1], (int) $parts[2] ] as $id ) {
				if ( $id > 0 ) {
					$names[] = get_the_title( $id );
				}
			}
			foreach ( array_filter( array_map( 'intval', explode( ',', $parts[3] ) ) ) as $gid ) {
				$names[] = get_the_title( $gid );
			}
			$names = array_filter( $names );
			if ( ! empty( $names ) ) {
				return implode( ' + ', $names );
			}
		}
		return $key;
	}

	private static function queue_rebuild( string $from, string $to ): void {
		$tz     = wp_timezone();
		$cursor = new \DateTimeImmutable( $from, $tz );
		$end    = new \DateTimeImmutable( $to, $tz );
		$guard  = 0;
		while ( $cursor <= $end && $guard < 400 ) {
			$date = $cursor->format( 'Y-m-d' );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( StatsRollup::HOOK_DATE, [ $date ], StatsRollup::GROUP );
			} else {
				StatsRollup::rollup_date( $date );
			}
			$cursor = $cursor->modify( '+1 day' );
			++$guard;
		}
	}

	private static function stream_csv( string $from, string $to, string $gran ): void {
		$series = self::series( $from, $to, $gran );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fn-fulfilment-' . $from . '_' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ ucfirst( $gran ), 'Meals', 'Deliveries', 'Collections', 'Revenue' ] );
		foreach ( $series as $r ) {
			fputcsv( $out, [ $r['bucket'], $r['meals'], $r['deliveries'], $r['collections'], number_format( (float) $r['revenue'], 2, '.', '' ) ] );
		}
		fclose( $out );
	}
}

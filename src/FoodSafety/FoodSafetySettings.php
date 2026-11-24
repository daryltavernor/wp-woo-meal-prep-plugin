<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

/**
 * Food-safety settings: critical limits, storage units, shelf life, record
 * retention, and the value pools the demo "sample readings" draw from.
 *
 * Everything lives in one option (`fn_food_safety`). get() always returns every
 * key with a default filled in, so callers never test for presence. The
 * defaults are the limits most UK kitchens run to under the FSA's Safer Food,
 * Better Business pack; the client confirms them against their own HACCP plan
 * on the settings page rather than typing them from scratch.
 */
final class FoodSafetySettings {

	public const OPTION    = 'fn_food_safety';
	public const PAGE_SLUG = 'fn-food-safety-settings';
	public const CRON_HOOK = 'fn_food_safety_purge';

	public const STORAGE_KINDS = [ 'chilled', 'frozen', 'ambient' ];

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_save' ] );
		add_action( 'admin_init', [ $this, 'handle_seed' ] );
		add_action( 'init', [ $this, 'ensure_cron' ] );
		add_action( self::CRON_HOOK, [ PrepRecordsRepository::class, 'purge_expired' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Catalogue of fixed choices ----------------------------------------

	/**
	 * Cooking methods, slug => label. `no_cook` is for bought-in / ready-to-eat
	 * items (sweets, salads) that get a supplier use-by check instead of a core
	 * temperature.
	 *
	 * @return array<string,string>
	 */
	public static function methods(): array {
		return [
			'oven'      => __( 'Oven', 'fastnutrition-mealprep' ),
			'grill'     => __( 'Grill', 'fastnutrition-mealprep' ),
			'pan'       => __( 'Pan / hob', 'fastnutrition-mealprep' ),
			'boil'      => __( 'Boil', 'fastnutrition-mealprep' ),
			'steam'     => __( 'Steam', 'fastnutrition-mealprep' ),
			'sous_vide' => __( 'Sous-vide', 'fastnutrition-mealprep' ),
			'air_fryer' => __( 'Air fryer', 'fastnutrition-mealprep' ),
			'no_cook'   => __( 'No cook / ready to eat', 'fastnutrition-mealprep' ),
		];
	}

	public static function method_label( string $slug ): string {
		$methods = self::methods();
		return $methods[ $slug ] ?? ( '' !== $slug ? ucfirst( str_replace( '_', ' ', $slug ) ) : '' );
	}

	/** @return array<string,string> */
	public static function storage_labels(): array {
		return [
			'chilled' => __( 'Chilled', 'fastnutrition-mealprep' ),
			'frozen'  => __( 'Frozen', 'fastnutrition-mealprep' ),
			'ambient' => __( 'Ambient', 'fastnutrition-mealprep' ),
		];
	}

	// --- Option access -----------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'cook_core_c'            => 75.0,
			'cool_within_min'        => 90,
			'fridge_target_c'        => 5.0,
			'fridge_max_c'           => 8.0,
			'freezer_max_c'          => -18.0,
			'dispatch_max_c'         => 8.0,
			'reheat_c'               => 75.0,
			'probe_calibration_days' => 7,
			'shelf_life_days'        => 3,
			'retention_months'       => 12,
			'signatory'              => __( 'Head chef', 'fastnutrition-mealprep' ),
			'fridges'                => [ __( 'Walk-in fridge', 'fastnutrition-mealprep' ) ],
			'freezers'               => [ __( 'Freezer 1', 'fastnutrition-mealprep' ) ],
			'sample'                 => [
				'window_start'  => '06:00',
				'window_end'    => '10:00',
				'initials'      => [ 'JB', 'KM', 'AS' ],
				'fridge_c_min'  => 2.0,
				'fridge_c_max'  => 4.6,
				'freezer_c_min' => -21.0,
				'freezer_c_max' => -18.4,
				'cool_min_min'  => 40,
				'cool_min_max'  => 85,
				'pack_gap_min'  => 10,
				'pack_gap_max'  => 40,
				'methods'       => [
					'oven'      => [ 'temp_min' => 76.0, 'temp_max' => 84.0, 'mins_min' => 25, 'mins_max' => 45 ],
					'grill'     => [ 'temp_min' => 76.0, 'temp_max' => 83.0, 'mins_min' => 12, 'mins_max' => 25 ],
					'pan'       => [ 'temp_min' => 76.0, 'temp_max' => 83.0, 'mins_min' => 10, 'mins_max' => 20 ],
					'boil'      => [ 'temp_min' => 88.0, 'temp_max' => 99.0, 'mins_min' => 10, 'mins_max' => 25 ],
					'steam'     => [ 'temp_min' => 85.0, 'temp_max' => 97.0, 'mins_min' => 8, 'mins_max' => 15 ],
					'sous_vide' => [ 'temp_min' => 75.0, 'temp_max' => 78.0, 'mins_min' => 60, 'mins_max' => 120 ],
					'air_fryer' => [ 'temp_min' => 76.0, 'temp_max' => 84.0, 'mins_min' => 15, 'mins_max' => 25 ],
				],
			],
		];
	}

	/**
	 * Saved settings over the defaults. Nested `sample` and `sample.methods`
	 * are merged key by key so a partially saved option still has every value.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		$out = array_merge( $defaults, $saved );

		$sample            = is_array( $saved['sample'] ?? null ) ? $saved['sample'] : [];
		$out['sample']     = array_merge( $defaults['sample'], $sample );
		$methods           = is_array( $sample['methods'] ?? null ) ? $sample['methods'] : [];
		$merged_methods    = [];
		foreach ( $defaults['sample']['methods'] as $slug => $def ) {
			$merged_methods[ $slug ] = array_merge( $def, is_array( $methods[ $slug ] ?? null ) ? $methods[ $slug ] : [] );
		}
		$out['sample']['methods'] = $merged_methods;

		foreach ( [ 'fridges', 'freezers' ] as $k ) {
			$out[ $k ] = array_values( array_filter( array_map( 'strval', (array) $out[ $k ] ), 'strlen' ) );
		}
		$out['sample']['initials'] = array_values( array_filter( array_map( 'strval', (array) $out['sample']['initials'] ), 'strlen' ) );
		return $out;
	}

	/** @return string[] */
	public static function fridges(): array {
		return (array) self::get()['fridges'];
	}

	/** @return string[] */
	public static function freezers(): array {
		return (array) self::get()['freezers'];
	}

	/**
	 * Whether $date is a probe-calibration day (every N days, counted from the
	 * Unix epoch so the cadence is stable and does not depend on when the plugin
	 * was installed). With the default of 7 this is the same weekday every week.
	 */
	public static function is_calibration_day( string $date ): bool {
		$days = max( 1, (int) self::get()['probe_calibration_days'] );
		$ts   = strtotime( $date . ' 12:00:00' );
		if ( false === $ts ) {
			return false;
		}
		return 0 === ( (int) floor( $ts / DAY_IN_SECONDS ) % $days );
	}

	// --- Cron for record retention ----------------------------------------

	public function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// --- Save ----------------------------------------------------------------

	public function handle_save(): void {
		if ( ! isset( $_POST['fn_food_safety_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_food_safety_settings', 'fn_food_safety_nonce' );

		$in  = isset( $_POST['fs'] ) && is_array( $_POST['fs'] ) ? wp_unslash( $_POST['fs'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field below.
		$num = static fn( $v, float $fallback ): float => is_numeric( $v ) ? (float) $v : $fallback;
		$int = static fn( $v, int $fallback ): int => is_numeric( $v ) ? (int) $v : $fallback;
		$d   = self::defaults();

		$lines = static function ( $v ): array {
			$parts = preg_split( '/\R/', (string) $v ) ?: [];
			return array_values( array_filter( array_map( static fn( $s ) => sanitize_text_field( trim( (string) $s ) ), $parts ), 'strlen' ) );
		};
		$csv   = static function ( $v ): array {
			$parts = explode( ',', (string) $v );
			return array_values( array_filter( array_map( static fn( $s ) => strtoupper( sanitize_text_field( trim( (string) $s ) ) ), $parts ), 'strlen' ) );
		};
		$time  = static function ( $v, string $fallback ): string {
			$v = trim( (string) $v );
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : $fallback;
		};

		$s   = isset( $in['sample'] ) && is_array( $in['sample'] ) ? $in['sample'] : [];
		$sm  = isset( $s['methods'] ) && is_array( $s['methods'] ) ? $s['methods'] : [];
		$out = [
			'cook_core_c'            => $num( $in['cook_core_c'] ?? null, (float) $d['cook_core_c'] ),
			'cool_within_min'        => max( 10, $int( $in['cool_within_min'] ?? null, (int) $d['cool_within_min'] ) ),
			'fridge_target_c'        => $num( $in['fridge_target_c'] ?? null, (float) $d['fridge_target_c'] ),
			'fridge_max_c'           => $num( $in['fridge_max_c'] ?? null, (float) $d['fridge_max_c'] ),
			'freezer_max_c'          => $num( $in['freezer_max_c'] ?? null, (float) $d['freezer_max_c'] ),
			'dispatch_max_c'         => $num( $in['dispatch_max_c'] ?? null, (float) $d['dispatch_max_c'] ),
			'reheat_c'               => $num( $in['reheat_c'] ?? null, (float) $d['reheat_c'] ),
			'probe_calibration_days' => max( 1, $int( $in['probe_calibration_days'] ?? null, (int) $d['probe_calibration_days'] ) ),
			'shelf_life_days'        => max( 1, $int( $in['shelf_life_days'] ?? null, (int) $d['shelf_life_days'] ) ),
			'retention_months'       => max( 0, $int( $in['retention_months'] ?? null, (int) $d['retention_months'] ) ),
			'signatory'              => sanitize_text_field( (string) ( $in['signatory'] ?? $d['signatory'] ) ),
			'fridges'                => $lines( $in['fridges'] ?? '' ),
			'freezers'               => $lines( $in['freezers'] ?? '' ),
			'sample'                 => [
				'window_start'  => $time( $s['window_start'] ?? '', $d['sample']['window_start'] ),
				'window_end'    => $time( $s['window_end'] ?? '', $d['sample']['window_end'] ),
				'initials'      => $csv( $s['initials'] ?? '' ),
				'fridge_c_min'  => $num( $s['fridge_c_min'] ?? null, (float) $d['sample']['fridge_c_min'] ),
				'fridge_c_max'  => $num( $s['fridge_c_max'] ?? null, (float) $d['sample']['fridge_c_max'] ),
				'freezer_c_min' => $num( $s['freezer_c_min'] ?? null, (float) $d['sample']['freezer_c_min'] ),
				'freezer_c_max' => $num( $s['freezer_c_max'] ?? null, (float) $d['sample']['freezer_c_max'] ),
				'cool_min_min'  => max( 1, $int( $s['cool_min_min'] ?? null, (int) $d['sample']['cool_min_min'] ) ),
				'cool_min_max'  => max( 1, $int( $s['cool_min_max'] ?? null, (int) $d['sample']['cool_min_max'] ) ),
				'pack_gap_min'  => max( 0, $int( $s['pack_gap_min'] ?? null, (int) $d['sample']['pack_gap_min'] ) ),
				'pack_gap_max'  => max( 0, $int( $s['pack_gap_max'] ?? null, (int) $d['sample']['pack_gap_max'] ) ),
				'methods'       => [],
			],
		];
		foreach ( $d['sample']['methods'] as $slug => $def ) {
			$row = isset( $sm[ $slug ] ) && is_array( $sm[ $slug ] ) ? $sm[ $slug ] : [];
			$out['sample']['methods'][ $slug ] = [
				'temp_min' => $num( $row['temp_min'] ?? null, (float) $def['temp_min'] ),
				'temp_max' => $num( $row['temp_max'] ?? null, (float) $def['temp_max'] ),
				'mins_min' => max( 1, $int( $row['mins_min'] ?? null, (int) $def['mins_min'] ) ),
				'mins_max' => max( 1, $int( $row['mins_max'] ?? null, (int) $def['mins_max'] ) ),
			];
		}
		if ( empty( $out['sample']['initials'] ) ) {
			$out['sample']['initials'] = $d['sample']['initials'];
		}
		if ( empty( $out['fridges'] ) ) {
			$out['fridges'] = $d['fridges'];
		}

		update_option( self::OPTION, $out, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
		exit;
	}

	/** Apply the name-based suggestions to the ingredients (blank ones, or all). */
	public function handle_seed(): void {
		if ( ! isset( $_POST['fn_seed_action'], $_POST['fn_food_safety_seed_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_food_safety_seed', 'fn_food_safety_seed_nonce' );
		$action = sanitize_key( wp_unslash( (string) $_POST['fn_seed_action'] ) );
		$n      = IngredientSafetySeeder::apply( 'overwrite' === $action );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&seeded=' . (int) $n . '#fn-ingredient-values' ) );
		exit;
	}

	// --- Render --------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}
		$v = self::get();
		$s = $v['sample'];

		echo '<div class="wrap"><h1>' . esc_html__( 'Food Safety Settings', 'fastnutrition-mealprep' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'fastnutrition-mealprep' ) . '</p></div>';
		}
		if ( isset( $_GET['seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$n = (int) $_GET['seeded']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: ingredients */ _n( 'Suggested food-safety values applied to %d ingredient.', 'Suggested food-safety values applied to %d ingredients.', $n, 'fastnutrition-mealprep' ), $n ) ) . '</p></div>';
		}
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:12px 0;max-width:900px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page is for', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'The limits the Cook & Pack Record and Prep Records check against, the fridges and freezers that appear on the daily checks, and the value pools used when a sheet is filled with sample readings for demonstration. Defaults follow the FSA Safer Food, Better Business guidance; confirm them against your own HACCP plan.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		echo '<form method="post" style="max-width:900px">';
		wp_nonce_field( 'fn_food_safety_settings', 'fn_food_safety_nonce' );

		echo '<h2>' . esc_html__( 'Critical limits', 'fastnutrition-mealprep' ) . '</h2><table class="form-table"><tbody>';
		$this->number_row( 'cook_core_c', __( 'Cooking core temperature (°C)', 'fastnutrition-mealprep' ), $v['cook_core_c'], 0.5, __( 'Every cooked component is probed to at least this. 75 °C is the standard UK figure.', 'fastnutrition-mealprep' ) );
		$this->number_row( 'cool_within_min', __( 'Cool and refrigerate within (minutes)', 'fastnutrition-mealprep' ), $v['cool_within_min'], 5, __( 'Cooked food must be in chilled storage within this long of coming out of the oven. SFBB uses 90 minutes.', 'fastnutrition-mealprep' ) );
		$this->number_row( 'fridge_target_c', __( 'Fridge target (°C)', 'fastnutrition-mealprep' ), $v['fridge_target_c'], 0.5, '' );
		$this->number_row( 'fridge_max_c', __( 'Fridge maximum (°C)', 'fastnutrition-mealprep' ), $v['fridge_max_c'], 0.5, __( '8 °C is the legal maximum for chilled food in England, Wales and Northern Ireland.', 'fastnutrition-mealprep' ) );
		$this->number_row( 'freezer_max_c', __( 'Freezer maximum (°C)', 'fastnutrition-mealprep' ), $v['freezer_max_c'], 0.5, '' );
		$this->number_row( 'dispatch_max_c', __( 'Dispatch / delivery box maximum (°C)', 'fastnutrition-mealprep' ), $v['dispatch_max_c'], 0.5, '' );
		$this->number_row( 'reheat_c', __( 'Reheating core temperature (°C)', 'fastnutrition-mealprep' ), $v['reheat_c'], 0.5, __( '75 °C in England, Wales and Northern Ireland; 82 °C in Scotland.', 'fastnutrition-mealprep' ) );
		$this->number_row( 'probe_calibration_days', __( 'Probe calibration every (days)', 'fastnutrition-mealprep' ), $v['probe_calibration_days'], 1, __( 'Iced water (0 °C) and boiling water (100 °C) check. 7 = weekly, on the same weekday.', 'fastnutrition-mealprep' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Storage units', 'fastnutrition-mealprep' ) . '</h2><table class="form-table"><tbody>';
		$this->textarea_row( 'fridges', __( 'Fridges (one per line)', 'fastnutrition-mealprep' ), implode( "\n", $v['fridges'] ), __( 'Each gets an AM and PM temperature line on the daily checks.', 'fastnutrition-mealprep' ) );
		$this->textarea_row( 'freezers', __( 'Freezers (one per line)', 'fastnutrition-mealprep' ), implode( "\n", $v['freezers'] ), '' );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Shelf life, sign-off and retention', 'fastnutrition-mealprep' ) . '</h2><table class="form-table"><tbody>';
		$this->number_row( 'shelf_life_days', __( 'Default shelf life (days)', 'fastnutrition-mealprep' ), $v['shelf_life_days'], 1, __( 'Used for the use-by date unless an ingredient sets its own. The fulfilment day counts as day 1.', 'fastnutrition-mealprep' ) );
		$this->text_row( 'signatory', __( 'Signed off by (role)', 'fastnutrition-mealprep' ), (string) $v['signatory'], __( 'Printed next to the sign-off box on the record.', 'fastnutrition-mealprep' ) );
		$this->number_row( 'retention_months', __( 'Keep digital records for (months)', 'fastnutrition-mealprep' ), $v['retention_months'], 1, __( 'Older Prep Records are deleted automatically each night. 0 keeps everything. Agree the period with your EHO.', 'fastnutrition-mealprep' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sample readings (demonstration)', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description" style="max-width:800px">' . esc_html__( 'When a Cook & Pack Record is printed with "Fill with sample readings", or demo records are seeded in the archive, the values are drawn from these pools. They are generated from the date and batch code, so the same day always produces the same figures. Sample-filled sheets carry a small specimen line in the footer and sample records are flagged in the archive.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th>%s</th><td><input type="time" name="fs[sample][window_start]" value="%s" /> &nbsp;%s&nbsp; <input type="time" name="fs[sample][window_end]" value="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Cooking window', 'fastnutrition-mealprep' ),
			esc_attr( (string) $s['window_start'] ),
			esc_html__( 'to', 'fastnutrition-mealprep' ),
			esc_attr( (string) $s['window_end'] ),
			esc_html__( 'Start and finish times for every batch fall inside this window on the prep date.', 'fastnutrition-mealprep' )
		);
		$this->text_row( 'sample][initials', __( 'Initials pool (comma-separated)', 'fastnutrition-mealprep' ), implode( ', ', $s['initials'] ), __( 'Who "signed" each sample row.', 'fastnutrition-mealprep' ) );
		$this->range_row( 'fridge_c', __( 'Fridge readings (°C)', 'fastnutrition-mealprep' ), $s['fridge_c_min'], $s['fridge_c_max'], 0.1 );
		$this->range_row( 'freezer_c', __( 'Freezer readings (°C)', 'fastnutrition-mealprep' ), $s['freezer_c_min'], $s['freezer_c_max'], 0.1 );
		$this->range_row( 'cool_min', __( 'Minutes from oven to fridge', 'fastnutrition-mealprep' ), $s['cool_min_min'], $s['cool_min_max'], 1 );
		$this->range_row( 'pack_gap', __( 'Minutes from fridge to packed', 'fastnutrition-mealprep' ), $s['pack_gap_min'], $s['pack_gap_max'], 1 );
		echo '</tbody></table>';

		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Core °C from', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'to', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Cook minutes from', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'to', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
		foreach ( $s['methods'] as $slug => $m ) {
			printf(
				'<tr><td><strong>%s</strong></td><td><input type="number" step="0.5" name="fs[sample][methods][%2$s][temp_min]" value="%3$s" style="width:90px" /></td><td><input type="number" step="0.5" name="fs[sample][methods][%2$s][temp_max]" value="%4$s" style="width:90px" /></td><td><input type="number" step="1" name="fs[sample][methods][%2$s][mins_min]" value="%5$s" style="width:90px" /></td><td><input type="number" step="1" name="fs[sample][methods][%2$s][mins_max]" value="%6$s" style="width:90px" /></td></tr>',
				esc_html( self::method_label( (string) $slug ) ),
				esc_attr( (string) $slug ),
				esc_attr( (string) $m['temp_min'] ),
				esc_attr( (string) $m['temp_max'] ),
				esc_attr( (string) $m['mins_min'] ),
				esc_attr( (string) $m['mins_max'] )
			);
		}
		echo '</tbody></table>';

		submit_button( __( 'Save food safety settings', 'fastnutrition-mealprep' ) );
		echo '</form>';

		$this->render_seed_section();
		echo '</div>';
	}

	/**
	 * The per-ingredient values worked out from each name, with one-click apply.
	 */
	private function render_seed_section(): void {
		$rows     = IngredientSafetySeeder::preview();
		$blank    = count( array_filter( $rows, static fn( array $r ): bool => $r['blank'] ) );
		$settings = self::get();

		echo '<h2 id="fn-ingredient-values" style="margin-top:32px">' . esc_html__( 'Ingredient food-safety values', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description" style="max-width:900px">' . esc_html__( 'Each ingredient carries its own cooking method, target core temperature, typical-cook reminder, cooling window, shelf life and batch code (the Food safety box on the ingredient). The table shows what the system suggests from the name — chicken roasts, steak is pan-cooked through, chilli and curry simmer, rice boils and must be chilled within the hour, greens steam, avocado and bought-in sweets are no-cook. Apply the suggestions, then correct any exceptions on the ingredient itself.', 'fastnutrition-mealprep' ) . '</p>';

		echo '<form method="post" style="margin:10px 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
		wp_nonce_field( 'fn_food_safety_seed', 'fn_food_safety_seed_nonce' );
		printf(
			'<button class="button button-primary" name="fn_seed_action" value="apply_blank" %s>%s</button>',
			$blank > 0 ? '' : 'disabled',
			esc_html( sprintf( /* translators: %d: ingredients without values */ __( 'Apply suggestions to the %d ingredients with no values', 'fastnutrition-mealprep' ), $blank ) )
		);
		printf(
			'<button class="button" name="fn_seed_action" value="overwrite" onclick="return confirm(%s)">%s</button>',
			esc_attr( wp_json_encode( __( 'Replace the food-safety values on every ingredient with the suggestions? Values you have set by hand will be overwritten.', 'fastnutrition-mealprep' ) ) ),
			esc_html__( 'Overwrite all with suggestions', 'fastnutrition-mealprep' )
		);
		echo '</form>';

		echo '<div style="overflow-x:auto"><table class="widefat striped" style="min-width:1000px"><thead><tr>';
		foreach ( [ __( 'Ingredient', 'fastnutrition-mealprep' ), __( 'Type', 'fastnutrition-mealprep' ), __( 'Now', 'fastnutrition-mealprep' ), __( 'Suggested method', 'fastnutrition-mealprep' ), __( 'Target', 'fastnutrition-mealprep' ), __( 'Typical cook (reminder)', 'fastnutrition-mealprep' ), __( 'Chill within', 'fastnutrition-mealprep' ), __( 'Shelf life', 'fastnutrition-mealprep' ), __( 'Code', 'fastnutrition-mealprep' ) ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$s       = $r['suggested'];
			$no_cook = 'no_cook' === ( $s['method'] ?? '' );
			$current = $r['blank']
				? '<em>' . esc_html__( 'defaults', 'fastnutrition-mealprep' ) . '</em>'
				: esc_html( self::method_label( (string) ( $r['current']['method'] ?? '' ) ) ?: __( 'set', 'fastnutrition-mealprep' ) );
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><small>%s</small></td><td>%s</td><td>%s</td><td style="font-family:monospace">%s</td></tr>',
				esc_url( get_edit_post_link( $r['id'] ) ?: '#' ),
				esc_html( $r['name'] ),
				esc_html( ucfirst( str_replace( '_', ' ', $r['type'] ) ) ),
				$current, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( self::method_label( (string) ( $s['method'] ?? '' ) ) ),
				esc_html( $no_cook ? '—' : ( '' !== (string) ( $s['target_c'] ?? '' ) ? (string) $s['target_c'] . ' °C' : (string) $settings['cook_core_c'] . ' °C' ) ),
				esc_html( (string) ( $s['typical'] ?? '' ) ),
				esc_html( $no_cook ? '—' : ( '' !== (string) ( $s['cool_within_min'] ?? '' ) ? (string) $s['cool_within_min'] . ' min' : (string) $settings['cool_within_min'] . ' min' ) ),
				esc_html( ( '' !== (string) ( $s['shelf_life_days'] ?? '' ) ? (string) $s['shelf_life_days'] : (string) $settings['shelf_life_days'] ) . ' ' . __( 'days', 'fastnutrition-mealprep' ) ),
				esc_html( (string) ( $s['short_code'] ?? '' ) )
			);
		}
		echo '</tbody></table></div>';
	}

	private function number_row( string $key, string $label, $value, float $step, string $help ): void {
		printf(
			'<tr><th><label for="fs_%1$s">%2$s</label></th><td><input type="number" step="%3$s" id="fs_%1$s" name="fs[%1$s]" value="%4$s" style="width:120px" />%5$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $step ),
			esc_attr( (string) $value ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function text_row( string $key, string $label, string $value, string $help ): void {
		printf(
			'<tr><th><label>%1$s</label></th><td><input type="text" class="regular-text" name="fs[%2$s]" value="%3$s" />%4$s</td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $value ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function textarea_row( string $key, string $label, string $value, string $help ): void {
		printf(
			'<tr><th><label for="fs_%1$s">%2$s</label></th><td><textarea id="fs_%1$s" name="fs[%1$s]" rows="3" class="regular-text">%3$s</textarea>%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( $value ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function range_row( string $key, string $label, $min, $max, float $step ): void {
		printf(
			'<tr><th>%1$s</th><td><input type="number" step="%2$s" name="fs[sample][%3$s_min]" value="%4$s" style="width:100px" /> &nbsp;%5$s&nbsp; <input type="number" step="%2$s" name="fs[sample][%3$s_max]" value="%6$s" style="width:100px" /></td></tr>',
			esc_html( $label ),
			esc_attr( (string) $step ),
			esc_attr( $key ),
			esc_attr( (string) $min ),
			esc_html__( 'to', 'fastnutrition-mealprep' ),
			esc_attr( (string) $max )
		);
	}
}

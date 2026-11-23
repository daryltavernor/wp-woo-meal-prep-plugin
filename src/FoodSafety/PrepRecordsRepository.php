<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

/**
 * Storage for digital prep records (Phase 2): one row per batch per day in
 * `fn_prep_records`, and the day's checks (fridge temperatures, probe
 * calibration, opening/closing, sign-off) as key/value rows in
 * `fn_daily_checks`.
 *
 * Rows seeded for demonstration carry `is_sample = 1`. Real entries never
 * overwrite each other silently: seeding skips any batch that already has a
 * real record, and saving a real value over a sample clears the flag.
 */
final class PrepRecordsRepository {

	/** Fields staff enter on a batch row. */
	public const FIELDS = [ 'time_in', 'time_out', 'probe1_c', 'probe2_c', 'cooled_by', 'fridge_unit', 'packed_at', 'use_by', 'initials', 'corrective_action' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_prep_records';
	}

	public static function checks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fn_daily_checks';
	}

	// --- Batch rows ------------------------------------------------------------

	/**
	 * Saved rows for a day, keyed by batch code, in insertion order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_day( string $date ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE record_date = %s ORDER BY id ASC", $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$out   = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['batch_code'] ] = self::normalise( $r );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private static function normalise( array $r ): array {
		return [
			'id'                => (int) $r['id'],
			'record_date'       => (string) $r['record_date'],
			'batch_code'        => (string) $r['batch_code'],
			'ingredient_id'     => (int) $r['ingredient_id'],
			'component'         => (string) $r['component'],
			'method'            => (string) $r['method'],
			'portions'          => (int) $r['portions'],
			'target_c'          => null === $r['target_c'] ? null : (float) $r['target_c'],
			'time_in'           => (string) $r['time_in'],
			'time_out'          => (string) $r['time_out'],
			'probe1_c'          => null === $r['probe1_c'] ? '' : (float) $r['probe1_c'],
			'probe2_c'          => null === $r['probe2_c'] ? '' : (float) $r['probe2_c'],
			'cooled_by'         => (string) $r['cooled_by'],
			'fridge_unit'       => (string) $r['fridge_unit'],
			'packed_at'         => (string) $r['packed_at'],
			'use_by'            => null === $r['use_by'] ? '' : (string) $r['use_by'],
			'initials'          => (string) $r['initials'],
			'corrective_action' => (string) $r['corrective_action'],
			'out_of_limit'      => (int) $r['out_of_limit'],
			'is_sample'         => (int) $r['is_sample'],
			'created_by'        => (int) $r['created_by'],
			'created_at'        => (string) $r['created_at'],
			'updated_at'        => (string) $r['updated_at'],
		];
	}

	/**
	 * Insert or update batch rows for a day.
	 *
	 * @param array<string,array<string,mixed>> $rows batch_code => row (base fields + entered fields).
	 */
	public static function upsert_rows( string $date, array $rows, int $user_id, int $is_sample = 0 ): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		foreach ( $rows as $code => $r ) {
			$code = (string) $code;
			if ( '' === $code ) {
				continue;
			}
			$data = [
				'record_date'       => $date,
				'batch_code'        => substr( $code, 0, 40 ),
				'ingredient_id'     => (int) ( $r['ingredient_id'] ?? 0 ),
				'component'         => substr( (string) ( $r['name'] ?? $r['component'] ?? '' ), 0, 191 ),
				'method'            => substr( (string) ( $r['method'] ?? '' ), 0, 20 ),
				'portions'          => (int) ( $r['portions'] ?? 0 ),
				'target_c'          => isset( $r['target_c'] ) && is_numeric( $r['target_c'] ) && empty( $r['no_cook'] ) ? (float) $r['target_c'] : null,
				'time_in'           => self::clean_time( $r['time_in'] ?? '' ),
				'time_out'          => self::clean_time( $r['time_out'] ?? '' ),
				'probe1_c'          => self::clean_num( $r['probe1_c'] ?? '' ),
				'probe2_c'          => self::clean_num( $r['probe2_c'] ?? '' ),
				'cooled_by'         => self::clean_time( $r['cooled_by'] ?? '' ),
				'fridge_unit'       => substr( sanitize_text_field( (string) ( $r['fridge_unit'] ?? '' ) ), 0, 100 ),
				'packed_at'         => self::clean_time( $r['packed_at'] ?? '' ),
				'use_by'            => self::clean_date( $r['use_by'] ?? '' ),
				'initials'          => strtoupper( substr( sanitize_text_field( (string) ( $r['initials'] ?? '' ) ), 0, 10 ) ),
				'corrective_action' => sanitize_textarea_field( (string) ( $r['corrective_action'] ?? '' ) ),
				'out_of_limit'      => empty( $r['out_of_limit'] ) ? 0 : 1,
				'is_sample'         => $is_sample ? 1 : 0,
				'updated_at'        => $now,
			];
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE record_date = %s AND batch_code = %s", $date, $data['batch_code'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $existing ) {
				$wpdb->update( $table, $data, [ 'id' => (int) $existing ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$data['created_by'] = $user_id;
				$data['created_at'] = $now;
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	public static function delete_row( string $date, string $code ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'record_date' => $date, 'batch_code' => $code ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Just the entered fields (plus flags) of a saved row.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	public static function entered_fields( array $record ): array {
		$out = [];
		foreach ( self::FIELDS as $f ) {
			$out[ $f ] = $record[ $f ] ?? '';
		}
		$out['out_of_limit'] = (int) ( $record['out_of_limit'] ?? 0 );
		$out['is_sample']    = (int) ( $record['is_sample'] ?? 0 );
		$out['record_id']    = (int) ( $record['id'] ?? 0 );
		return $out;
	}

	/** Whether two sets of entered fields are the same once normalised. */
	public static function same_entries( array $a, array $b ): bool {
		foreach ( self::FIELDS as $f ) {
			$x = trim( (string) ( $a[ $f ] ?? '' ) );
			$y = trim( (string) ( $b[ $f ] ?? '' ) );
			if ( is_numeric( $x ) && is_numeric( $y ) ) {
				if ( abs( (float) $x - (float) $y ) > 0.001 ) {
					return false;
				}
			} elseif ( strtoupper( $x ) !== strtoupper( $y ) ) {
				return false;
			}
		}
		return true;
	}

	// --- Evaluation against the limits --------------------------------------

	/**
	 * Check entered readings against the batch's target and the cooling window.
	 *
	 * @param array<string,mixed> $fields   Entered fields.
	 * @param array<string,mixed> $row      Base row (target_c, cooling, no_cook).
	 * @param array<string,mixed> $settings FoodSafetySettings::get().
	 * @return array{out_of_limit:bool,reasons:string[]}
	 */
	public static function evaluate( array $fields, array $row, array $settings ): array {
		$reasons = [];
		if ( empty( $row['no_cook'] ) ) {
			$target = isset( $row['target_c'] ) && is_numeric( $row['target_c'] ) ? (float) $row['target_c'] : (float) $settings['cook_core_c'];
			foreach ( [ 'probe1_c', 'probe2_c' ] as $k ) {
				$v = $fields[ $k ] ?? '';
				if ( is_numeric( $v ) && (float) $v < $target ) {
					/* translators: 1: reading, 2: target */
					$reasons[] = sprintf( __( 'Core %1$s °C is below the %2$s °C target', 'fastnutrition-mealprep' ), (string) (float) $v, (string) $target );
				}
			}
			if ( ! empty( $row['cooling'] ) ) {
				$out    = self::minutes( (string) ( $fields['time_out'] ?? '' ) );
				$cooled = self::minutes( (string) ( $fields['cooled_by'] ?? '' ) );
				if ( null !== $out && null !== $cooled ) {
					$diff = $cooled - $out;
					if ( $diff < 0 ) {
						$diff += 24 * 60;
					}
					if ( $diff > (int) $settings['cool_within_min'] ) {
						/* translators: 1: minutes taken, 2: minutes allowed */
						$reasons[] = sprintf( __( 'Took %1$d minutes to refrigerate (limit %2$d)', 'fastnutrition-mealprep' ), $diff, (int) $settings['cool_within_min'] );
					}
				}
			}
		}
		return [
			'out_of_limit' => ! empty( $reasons ),
			'reasons'      => $reasons,
		];
	}

	// --- Daily checks ----------------------------------------------------------

	/**
	 * The day's checks as key => value. Adds `__sample` => '1' when any of the
	 * saved checks were seeded as sample data.
	 *
	 * @return array<string,string>
	 */
	public static function get_checks( string $date ): array {
		global $wpdb;
		$table = self::checks_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT check_key, check_value, is_sample FROM {$table} WHERE check_date = %s", $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['check_key'] ] = (string) $r['check_value'];
			if ( (int) $r['is_sample'] ) {
				$out['__sample'] = '1';
			}
		}
		return $out;
	}

	/**
	 * @param array<string,string> $kv key => value; '' deletes the key.
	 */
	public static function save_checks( string $date, array $kv, int $is_sample = 0 ): void {
		global $wpdb;
		$table = self::checks_table();
		$now   = current_time( 'mysql' );
		foreach ( $kv as $key => $value ) {
			$key = substr( (string) $key, 0, 120 );
			if ( '' === $key || '__sample' === $key ) {
				continue;
			}
			$value = substr( sanitize_text_field( (string) $value ), 0, 191 );
			if ( '' === $value ) {
				$wpdb->delete( $table, [ 'check_date' => $date, 'check_key' => $key ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE check_date = %s AND check_key = %s", $date, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data     = [
				'check_date'  => $date,
				'check_key'   => $key,
				'check_value' => $value,
				'is_sample'   => $is_sample ? 1 : 0,
				'updated_at'  => $now,
			];
			if ( $existing ) {
				$wpdb->update( $table, $data, [ 'id' => (int) $existing ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	// --- Archive queries -------------------------------------------------------

	/**
	 * @param array<string,mixed> $f from, to (Y-m-d), ingredient_id, ingredient_ids (int[]), batch (substring), out_only (bool), include_samples (bool), limit (int)
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $f ): array {
		global $wpdb;
		$table = self::table();
		$where = [ '1=1' ];
		$args  = [];
		if ( ! empty( $f['from'] ) ) {
			$where[] = 'record_date >= %s';
			$args[]  = (string) $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = 'record_date <= %s';
			$args[]  = (string) $f['to'];
		}
		if ( ! empty( $f['ingredient_id'] ) ) {
			$where[] = 'ingredient_id = %d';
			$args[]  = (int) $f['ingredient_id'];
		}
		if ( ! empty( $f['ingredient_ids'] ) && is_array( $f['ingredient_ids'] ) ) {
			$ids     = array_map( 'intval', $f['ingredient_ids'] );
			$where[] = 'ingredient_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args    = array_merge( $args, $ids );
		}
		if ( ! empty( $f['batch'] ) ) {
			$where[] = 'batch_code LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $f['batch'] ) . '%';
		}
		if ( ! empty( $f['out_only'] ) ) {
			$where[] = 'out_of_limit = 1';
		}
		if ( empty( $f['include_samples'] ) ) {
			$where[] = 'is_sample = 0';
		}
		$limit = isset( $f['limit'] ) ? max( 1, (int) $f['limit'] ) : 500;
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY record_date DESC, id ASC LIMIT ' . $limit;
		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( [ self::class, 'normalise' ], (array) $rows );
	}

	/**
	 * Per-month counts for the last 12 months with records.
	 *
	 * @return array<int,array{month:string,batches:int,out_of_limit:int,days:int,check_days:int,samples:int}>
	 */
	public static function monthly_summary( bool $include_samples ): array {
		global $wpdb;
		$table  = self::table();
		$checks = self::checks_table();
		$filter = $include_samples ? '' : ' WHERE is_sample = 0';
		// No user input in these statements; the only variable part is the constant filter above.
		$rows = $wpdb->get_results( "SELECT DATE_FORMAT(record_date, '%Y-%m') AS ym, COUNT(*) AS batches, SUM(out_of_limit) AS out_of_limit, COUNT(DISTINCT record_date) AS days, SUM(is_sample) AS samples FROM {$table}{$filter} GROUP BY ym ORDER BY ym DESC LIMIT 12", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$cd   = $wpdb->get_results( "SELECT DATE_FORMAT(check_date, '%Y-%m') AS ym, COUNT(DISTINCT check_date) AS days FROM {$checks}{$filter} GROUP BY ym", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$check_days = [];
		foreach ( (array) $cd as $r ) {
			$check_days[ (string) $r['ym'] ] = (int) $r['days'];
		}
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'month'        => (string) $r['ym'],
				'batches'      => (int) $r['batches'],
				'out_of_limit' => (int) $r['out_of_limit'],
				'days'         => (int) $r['days'],
				'check_days'   => $check_days[ (string) $r['ym'] ] ?? 0,
				'samples'      => (int) $r['samples'],
			];
		}
		return $out;
	}

	// --- Retention and demo data -----------------------------------------------

	/** Nightly: delete records older than the retention period (0 = keep forever). */
	public static function purge_expired(): void {
		global $wpdb;
		$months = (int) FoodSafetySettings::get()['retention_months'];
		if ( $months <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d', (int) strtotime( '-' . $months . ' months' ) );
		$table  = self::table();
		$checks = self::checks_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE record_date < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$checks} WHERE check_date < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function count_samples(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_sample = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/** Remove every seeded sample row and sample check. Returns rows removed. */
	public static function delete_samples(): int {
		global $wpdb;
		$table  = self::table();
		$checks = self::checks_table();
		$n      = (int) $wpdb->query( "DELETE FROM {$table} WHERE is_sample = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$checks} WHERE is_sample = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $n;
	}

	/**
	 * Seed sample records for every day in the range that has a prep list.
	 * Never touches a batch that already has a real record; re-seeding a day
	 * overwrites its earlier sample rows with the same (deterministic) values.
	 *
	 * @return array{days:int,rows:int}
	 */
	public static function seed_samples( string $from, string $to, int $user_id ): array {
		$settings = FoodSafetySettings::get();
		$start    = strtotime( $from . ' 12:00:00' );
		$end      = strtotime( $to . ' 12:00:00' );
		$days     = 0;
		$rows     = 0;
		if ( false === $start || false === $end ) {
			return [ 'days' => 0, 'rows' => 0 ];
		}
		if ( $end < $start ) {
			[ $start, $end ] = [ $end, $start ];
		}
		$end = min( $end, $start + 61 * DAY_IN_SECONDS );

		for ( $ts = $start; $ts <= $end; $ts += DAY_IN_SECONDS ) {
			$date = gmdate( 'Y-m-d', $ts );
			$base = CookPackRecord::rows( $date );
			if ( empty( $base ) ) {
				continue;
			}
			$saved = self::get_day( $date );
			$batch = [];
			foreach ( $base as $row ) {
				$code = (string) $row['batch_code'];
				if ( isset( $saved[ $code ] ) && ! $saved[ $code ]['is_sample'] ) {
					continue; // A real record exists for this batch.
				}
				$fields = SampleReadings::for_row( $date, $row, $settings );
				$eval   = self::evaluate( $fields, $row, $settings );
				$batch[ $code ] = array_merge( $row, $fields, [ 'out_of_limit' => $eval['out_of_limit'] ? 1 : 0 ] );
			}
			if ( $batch ) {
				self::upsert_rows( $date, $batch, $user_id, 1 );
				$rows += count( $batch );
			}
			$checks = self::get_checks( $date );
			if ( empty( $checks ) || ! empty( $checks['__sample'] ) ) {
				self::save_checks( $date, SampleReadings::for_checks( $date, $settings ), 1 );
			}
			$days++;
		}
		return [ 'days' => $days, 'rows' => $rows ];
	}

	// --- Field cleaners ------------------------------------------------------------

	public static function clean_time( $v ): string {
		$v = trim( (string) $v );
		if ( preg_match( '/^(\d{1,2}):(\d{2})/', $v, $m ) ) {
			$h = min( 23, (int) $m[1] );
			return sprintf( '%02d:%02d', $h, min( 59, (int) $m[2] ) );
		}
		return '';
	}

	public static function clean_num( $v ): ?float {
		$v = trim( (string) $v );
		return is_numeric( $v ) ? round( (float) $v, 1 ) : null;
	}

	public static function clean_date( $v ): ?string {
		$v = trim( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	/** Minutes since midnight for "HH:MM", or null when blank/invalid. */
	public static function minutes( string $hhmm ): ?int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}
}

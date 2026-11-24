<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

/**
 * Believable sample readings for demonstrating the record sheets.
 *
 * Every value is drawn from the pools in Food Safety Settings (temperature and
 * cook-time ranges per method, the 06:00–10:00 cooking window, cooling and
 * packing gaps, fridge/freezer ranges, an initials pool). The generator is
 * seeded from the prep date and batch code, so the same day prints the same
 * figures every time and a demo can be repeated. Readings always land inside
 * the critical limits.
 *
 * Sample values are for demonstration: a sheet filled with them carries a
 * specimen line in its footer and seeded records are flagged `is_sample`.
 */
final class SampleReadings {

	/**
	 * Readings for one Cook & Pack row.
	 *
	 * @param array<string,mixed> $row      A row from CookPackRecord::rows().
	 * @param array<string,mixed> $settings FoodSafetySettings::get().
	 * @return array<string,mixed> Field => value, same keys as a saved record.
	 */
	public static function for_row( string $date, array $row, array $settings ): array {
		$s = $settings['sample'];
		self::seed( 'fn-sample', $date, (string) $row['batch_code'] );

		$win_start = self::to_minutes( (string) $s['window_start'] );
		$win_end   = max( $win_start + 30, self::to_minutes( (string) $s['window_end'] ) );
		$initials  = (array) $s['initials'];

		if ( ! empty( $row['no_cook'] ) ) {
			$t   = mt_rand( $win_start, $win_end );
			$out = [
				'time_in'   => self::to_hhmm( $t ),
				'packed_at' => self::to_hhmm( $t + mt_rand( (int) $s['pack_gap_min'], max( (int) $s['pack_gap_min'], (int) $s['pack_gap_max'] ) ) ),
				'initials'  => self::pick( $initials ),
				'is_sample' => 1,
			];
			self::unseed();
			return $out;
		}

		$method = (string) ( $row['method'] ?? 'oven' );
		$m      = $s['methods'][ $method ] ?? $s['methods']['oven'];
		$target = (float) ( $row['target_c'] ?? $settings['cook_core_c'] );

		$duration     = mt_rand( (int) $m['mins_min'], max( (int) $m['mins_min'], (int) $m['mins_max'] ) );
		$latest_start = max( $win_start, $win_end - $duration );
		$time_in      = mt_rand( $win_start, $latest_start );
		$time_out     = $time_in + $duration;

		// Core temperatures: inside the method's pool and never below target.
		$t_min  = max( (float) $m['temp_min'], $target + 0.5 );
		$t_max  = max( (float) $m['temp_max'], $t_min + 1.0 );
		$probe1 = self::rand_float( $t_min, $t_max );
		$probe2 = round( $probe1 + self::rand_float( -0.8, 1.6 ), 1 );
		$probe2 = min( max( $probe2, $target + 0.3 ), $t_max + 1.0 );

		$fields = [
			'time_in'   => self::to_hhmm( $time_in ),
			'time_out'  => self::to_hhmm( $time_out ),
			'probe1_c'  => $probe1,
			'probe2_c'  => $probe2,
			'initials'  => self::pick( $initials ),
			'is_sample' => 1,
		];

		$packed_from = $time_out;
		if ( ! empty( $row['cooling'] ) ) {
			$limit  = PrepRecordsRepository::cool_limit( $row, $settings );
			$cool   = mt_rand( (int) $s['cool_min_min'], max( (int) $s['cool_min_min'], (int) $s['cool_min_max'] ) );
			$cool   = min( $cool, max( 5, $limit - 5 ) );
			$cooled = $time_out + $cool;

			$units = 'frozen' === ( $row['storage'] ?? 'chilled' ) ? (array) $settings['freezers'] : (array) $settings['fridges'];
			$fields['cooled_by']   = self::to_hhmm( $cooled );
			$fields['fridge_unit'] = self::pick( $units );
			$packed_from           = $cooled;
		}
		$fields['packed_at'] = self::to_hhmm( $packed_from + mt_rand( (int) $s['pack_gap_min'], max( (int) $s['pack_gap_min'], (int) $s['pack_gap_max'] ) ) );

		self::unseed();
		return $fields;
	}

	/**
	 * Sample daily checks for a date: fridge/freezer AM and PM readings, probe
	 * calibration on calibration days, opening/closing/cleaning ticks and a
	 * sign-off.
	 *
	 * @param array<string,mixed> $settings FoodSafetySettings::get().
	 * @return array<string,string> check key => value (see PrepRecordsRepository check keys).
	 */
	public static function for_checks( string $date, array $settings ): array {
		$s = $settings['sample'];
		self::seed( 'fn-sample-checks', $date );

		$out      = [];
		$win      = self::to_minutes( (string) $s['window_start'] );
		$am_time  = $win + mt_rand( 0, 30 );
		$pm_time  = mt_rand( 16 * 60, 18 * 60 );
		$initials = (array) $s['initials'];

		foreach ( (array) $settings['fridges'] as $name ) {
			$out[ 'unit_am:' . $name ] = (string) self::rand_float( (float) $s['fridge_c_min'], (float) $s['fridge_c_max'] );
			$out[ 'unit_pm:' . $name ] = (string) self::rand_float( (float) $s['fridge_c_min'], (float) $s['fridge_c_max'] );
		}
		foreach ( (array) $settings['freezers'] as $name ) {
			$out[ 'unit_am:' . $name ] = (string) self::rand_float( (float) $s['freezer_c_min'], (float) $s['freezer_c_max'] );
			$out[ 'unit_pm:' . $name ] = (string) self::rand_float( (float) $s['freezer_c_min'], (float) $s['freezer_c_max'] );
		}
		$out['am_time'] = self::to_hhmm( $am_time );
		$out['pm_time'] = self::to_hhmm( $pm_time );

		if ( FoodSafetySettings::is_calibration_day( $date ) ) {
			$out['probe_iced']    = (string) self::rand_float( -0.3, 0.6 );
			$out['probe_boiling'] = (string) self::rand_float( 99.3, 100.4 );
		}
		$out['opening']  = '1';
		$out['closing']  = '1';
		$out['cleaning'] = '1';
		$out['signoff']  = self::pick( $initials );

		self::unseed();
		return $out;
	}

	// --- Helpers -------------------------------------------------------------

	private static function seed( string ...$parts ): void {
		mt_srand( crc32( implode( '|', $parts ) ) );
	}

	/** Put the generator back to a random state so nothing else sees our seed. */
	private static function unseed(): void {
		mt_srand();
	}

	private static function rand_float( float $min, float $max, int $dp = 1 ): float {
		if ( $max < $min ) {
			[ $min, $max ] = [ $max, $min ];
		}
		$scale = 10 ** $dp;
		return mt_rand( (int) round( $min * $scale ), (int) round( $max * $scale ) ) / $scale;
	}

	/** @param array<int,mixed> $pool */
	private static function pick( array $pool ): string {
		$pool = array_values( array_filter( array_map( 'strval', $pool ), 'strlen' ) );
		if ( empty( $pool ) ) {
			return '';
		}
		return $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
	}

	private static function to_minutes( string $hhmm ): int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) {
			return 6 * 60;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	private static function to_hhmm( int $minutes ): string {
		$minutes = max( 0, $minutes ) % ( 24 * 60 );
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}
}

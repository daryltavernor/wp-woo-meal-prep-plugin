<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

use FastNutrition\MealPrep\Admin\PrepDashboard;

/**
 * Batch codes for a prep day: `YYMMDD-<short code>-<n>`, one per cooked
 * component per day (n = 1), with -2, -3… for additional batches staff add in
 * Prep Records. The code on the Cook & Pack Record and the code beside each
 * meal's components on the pick list are the same string, which is the whole
 * traceability link: order → components → batch → cook record.
 */
final class BatchCodes {

	/** @var array<string,array<int,string>> date => [ingredient_id => code] */
	private static array $memo = [];

	/**
	 * Codes for every component on the day's prep list (from the prep cache),
	 * keyed by ingredient id. Two ingredients that derive the same short code
	 * get it de-duplicated with a digit suffix, deterministically by prep-list
	 * order, so the mapping is stable for the day.
	 *
	 * @param array<int,array{ingredient_id:int,name:string,portions:int,type_slug:string}>|null $totals Pass the day's totals when already loaded.
	 * @return array<int,string>
	 */
	public static function map_for_day( string $date, ?array $totals = null ): array {
		if ( null === $totals && isset( self::$memo[ $date ] ) ) {
			return self::$memo[ $date ];
		}
		$totals = $totals ?? PrepDashboard::get_day_totals( $date );
		$prefix = self::date_prefix( $date );
		$used   = [];
		$map    = [];
		foreach ( $totals as $row ) {
			$iid  = (int) $row['ingredient_id'];
			$safe = IngredientSafety::resolve( $iid, (string) ( $row['type_slug'] ?? '' ) );
			$code = $safe['short_code'];
			$base = $code;
			$n    = 2;
			while ( isset( $used[ $code ] ) ) {
				$code = $base . $n;
				$n++;
			}
			$used[ $code ] = true;
			$map[ $iid ]   = $prefix . '-' . $code . '-1';
		}
		self::$memo[ $date ] = $map;
		return $map;
	}

	/** `YYMMDD` for a Y-m-d date. */
	public static function date_prefix( string $date ): string {
		$ts = strtotime( $date . ' 12:00:00' );
		return false !== $ts ? gmdate( 'ymd', $ts ) : '000000';
	}

	/** The code without its date prefix, for sheets that are already per-day ("CHC-1"). */
	public static function short( string $code ): string {
		$pos = strpos( $code, '-' );
		return false === $pos ? $code : substr( $code, $pos + 1 );
	}

	/** Replace the trailing batch number: with_sequence('261128-CHC-1', 2) → '261128-CHC-2'. */
	public static function with_sequence( string $code, int $seq ): string {
		return (string) preg_replace( '/-\d+$/', '-' . max( 1, $seq ), $code );
	}

	/** The trailing batch number of a code (1 when absent). */
	public static function sequence( string $code ): int {
		return preg_match( '/-(\d+)$/', $code, $m ) ? (int) $m[1] : 1;
	}

	/**
	 * Short codes for the components of one meal selection on its prep date,
	 * for the pick list ("CHC-1 · SWT-1 · BRC-1").
	 *
	 * @param int[] $ingredient_ids
	 * @return string[]
	 */
	public static function for_selection( string $date, array $ingredient_ids ): array {
		if ( '' === $date ) {
			return [];
		}
		$map = self::map_for_day( $date );
		$out = [];
		foreach ( $ingredient_ids as $iid ) {
			if ( isset( $map[ (int) $iid ] ) ) {
				$out[] = self::short( $map[ (int) $iid ] );
			}
		}
		return $out;
	}
}

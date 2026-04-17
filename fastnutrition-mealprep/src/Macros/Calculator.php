<?php
/**
 * Macro arithmetic utilities. Pure functions over selection arrays / ingredient arrays.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

use FastNutrition\MealPrep\PostTypes\Ingredient;

final class Calculator {

	public const KEYS = [ 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g' ];

	public function register(): void {
		// No hooks — consumed directly by Selections / prep dashboard / shortcode.
	}

	public static function empty(): array {
		return array_fill_keys( self::KEYS, 0.0 );
	}

	public static function add( array $a, array $b ): array {
		$out = self::empty();
		foreach ( self::KEYS as $k ) {
			$out[ $k ] = (float) ( $a[ $k ] ?? 0 ) + (float) ( $b[ $k ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Compute total macros for a meal selection (sum of component ingredient macros).
	 */
	public static function for_selection( array $selection ): array {
		$total = self::empty();

		if ( 'set' === ( $selection['mode'] ?? '' ) && ! empty( $selection['set_meal_id'] ) ) {
			$ing = Ingredient::get( (int) $selection['set_meal_id'] );
			if ( $ing ) {
				$total = self::add( $total, $ing['macros'] );
			}
			return $total;
		}

		foreach ( [ 'protein_id', 'carb_id' ] as $k ) {
			if ( ! empty( $selection[ $k ] ) ) {
				$ing = Ingredient::get( (int) $selection[ $k ] );
				if ( $ing ) {
					$total = self::add( $total, $ing['macros'] );
				}
			}
		}
		foreach ( (array) ( $selection['greens_ids'] ?? [] ) as $gid ) {
			$ing = Ingredient::get( (int) $gid );
			if ( $ing ) {
				$total = self::add( $total, $ing['macros'] );
			}
		}
		return $total;
	}
}

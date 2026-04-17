<?php
/**
 * Server-side storage for a logged-in customer's custom macro-calculator ingredients.
 * Also exposes helper endpoints for the front-end calculator to CRUD them.
 *
 * Custom ingredients are stored in user meta as an array of { id, name, macros }.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

final class CustomIngredientStore {

	public const USER_META = 'fn_custom_ingredients';

	public function register(): void {
		// REST routes live in RestController; this class is the data layer.
	}

	public static function get_for_user( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::USER_META, true );
		return is_array( $raw ) ? $raw : [];
	}

	public static function save_for_user( int $user_id, array $list ): void {
		$clean = [];
		foreach ( $list as $item ) {
			$name = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$macros = is_array( $item['macros'] ?? null ) ? $item['macros'] : [];
			$clean[] = [
				'id'     => sanitize_text_field( (string) ( $item['id'] ?? wp_generate_uuid4() ) ),
				'name'   => $name,
				'macros' => [
					'kcal'      => (float) ( $macros['kcal'] ?? 0 ),
					'protein_g' => (float) ( $macros['protein_g'] ?? 0 ),
					'carbs_g'   => (float) ( $macros['carbs_g'] ?? 0 ),
					'fat_g'     => (float) ( $macros['fat_g'] ?? 0 ),
					'fibre_g'   => (float) ( $macros['fibre_g'] ?? 0 ),
				],
			];
		}
		update_user_meta( $user_id, self::USER_META, $clean );
	}
}

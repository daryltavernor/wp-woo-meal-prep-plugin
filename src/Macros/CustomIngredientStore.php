<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

use WP_REST_Request;

final class CustomIngredientStore {

	public const META_KEY = 'fn_custom_ingredients';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'fastnutrition/v1',
			'/custom-ingredients',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_list' ],
					'permission_callback' => static fn() => is_user_logged_in(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save_list' ],
					'permission_callback' => static fn() => is_user_logged_in(),
					'args'                => [
						'items' => [
							'type'     => 'array',
							'required' => true,
						],
					],
				],
			]
		);
	}

	public function get_list( WP_REST_Request $request ): array {
		$list = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	public function save_list( WP_REST_Request $request ): array {
		$items = $request->get_param( 'items' );
		$items = is_array( $items ) ? $items : [];
		$clean = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$clean[] = [
				'id'        => isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : wp_generate_uuid4(),
				'name'      => sanitize_text_field( (string) $item['name'] ),
				'kcal'      => (float) ( $item['kcal'] ?? 0 ),
				'protein_g' => (float) ( $item['protein_g'] ?? 0 ),
				'carbs_g'   => (float) ( $item['carbs_g'] ?? 0 ),
				'fat_g'     => (float) ( $item['fat_g'] ?? 0 ),
				'fibre_g'   => (float) ( $item['fibre_g'] ?? 0 ),
			];
		}
		update_user_meta( get_current_user_id(), self::META_KEY, $clean );
		return [ 'items' => $clean ];
	}
}

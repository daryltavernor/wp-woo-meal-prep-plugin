<?php
/**
 * Public REST API for ingredients + slot availability + customer custom ingredients.
 *
 * Routes:
 *   GET  /fastnutrition/v1/ingredients           (grouped by type; ?product= filters by product allow-lists)
 *   GET  /fastnutrition/v1/slots?postcode=SW11AA
 *   GET  /fastnutrition/v1/custom-ingredients    (logged-in user only)
 *   POST /fastnutrition/v1/custom-ingredients    (logged-in user only)
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Rest;

use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Macros\CustomIngredientStore;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class RestController {

	public const NAMESPACE = 'fastnutrition/v1';

	public function __construct( private readonly SlotAvailability $slots ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ingredients',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_ingredients' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'product' => [ 'type' => 'integer', 'default' => 0 ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/slots',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_slots' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'postcode'    => [ 'type' => 'string', 'required' => true ],
						'window_days' => [ 'type' => 'integer', 'default' => 14 ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/custom-ingredients',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_custom' ],
					'permission_callback' => static fn() => is_user_logged_in(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save_custom' ],
					'permission_callback' => static fn() => is_user_logged_in(),
					'args'                => [
						'items' => [ 'type' => 'array', 'required' => true ],
					],
				],
			]
		);
	}

	public function get_ingredients( \WP_REST_Request $req ): \WP_REST_Response {
		$product_id = (int) $req->get_param( 'product' );
		$allow      = null;
		if ( $product_id && MealProduct::is_meal( $product_id ) ) {
			$cfg   = MealProduct::config( $product_id );
			$allow = [
				IngredientType::TERM_PROTEIN  => $cfg['allowed_protein'],
				IngredientType::TERM_CARB     => $cfg['allowed_carb'],
				IngredientType::TERM_GREENS   => $cfg['allowed_greens'],
				IngredientType::TERM_SET_MEAL => $cfg['allowed_set_meal'],
			];
		}

		$out = [
			IngredientType::TERM_PROTEIN  => [],
			IngredientType::TERM_CARB     => [],
			IngredientType::TERM_GREENS   => [],
			IngredientType::TERM_SET_MEAL => [],
		];

		foreach ( IngredientType::all_slugs() as $slug ) {
			$ids = get_posts(
				[
					'post_type'      => Ingredient::POST_TYPE,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'tax_query'      => [
						[ 'taxonomy' => IngredientType::SLUG, 'field' => 'slug', 'terms' => $slug ],
					],
					'meta_query'     => [
						[ 'key' => Ingredient::META_ACTIVE, 'value' => '1', 'compare' => '=' ],
					],
				]
			);
			foreach ( $ids as $id ) {
				$data = Ingredient::get( (int) $id );
				if ( ! $data ) {
					continue;
				}
				if ( $allow && ! empty( $allow[ $slug ] ) && ! in_array( (int) $id, $allow[ $slug ], true ) ) {
					continue;
				}
				$out[ $slug ][] = $data;
			}
		}

		return rest_ensure_response( $out );
	}

	public function get_slots( \WP_REST_Request $req ): \WP_REST_Response {
		$postcode = sanitize_text_field( (string) $req->get_param( 'postcode' ) );
		$window   = max( 1, min( 60, (int) $req->get_param( 'window_days' ) ) );
		$settings = get_option( 'fn_mealprep_settings', [] );
		$lead     = isset( $settings['min_lead_hours'] ) ? (int) $settings['min_lead_hours'] : 24;
		return rest_ensure_response( $this->slots->for_postcode( $postcode, $window, $lead ) );
	}

	public function get_custom( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( CustomIngredientStore::get_for_user( get_current_user_id() ) );
	}

	public function save_custom( \WP_REST_Request $req ): \WP_REST_Response {
		$items = $req->get_param( 'items' );
		CustomIngredientStore::save_for_user( get_current_user_id(), is_array( $items ) ? $items : [] );
		return rest_ensure_response( [ 'ok' => true ] );
	}
}

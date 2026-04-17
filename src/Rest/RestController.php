<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Rest;

use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\BundleMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_REST_Request;

final class RestController {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'fastnutrition/v1',
			'/ingredients',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_ingredients' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'type' => [ 'type' => 'string' ],
				],
			]
		);

		register_rest_route(
			'fastnutrition/v1',
			'/meal-config/(?P<product_id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_meal_config' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'fastnutrition/v1',
			'/slots',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_slots' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'postcode' => [ 'type' => 'string', 'required' => true ],
					'method'   => [ 'type' => 'string' ],
				],
			]
		);
	}

	public function get_ingredients( WP_REST_Request $req ): array {
		$type = sanitize_key( (string) $req->get_param( 'type' ) );
		$args = [
			'post_type'      => Ingredient::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_fn_active', 'value' => '1' ],
				[ 'key' => '_fn_active', 'compare' => 'NOT EXISTS' ],
			],
		];
		if ( $type ) {
			$args['tax_query'] = [
				[
					'taxonomy' => IngredientType::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $type,
				],
			];
		}
		$posts = get_posts( $args );
		$out   = [];
		foreach ( $posts as $p ) {
			$out[] = [
				'id'          => (int) $p->ID,
				'name'        => (string) $p->post_title,
				'type'        => Ingredient::get_type_slug( (int) $p->ID ),
				'tier'        => (string) ( get_post_meta( $p->ID, '_fn_tier', true ) ?: 'standard' ),
				'macros'      => Ingredient::get_macros( (int) $p->ID ),
				'price_delta' => (float) get_post_meta( $p->ID, '_fn_price_delta', true ),
				'allergens'   => wp_get_post_terms( (int) $p->ID, Allergen::TAXONOMY, [ 'fields' => 'slugs' ] ),
				'thumbnail'   => (string) get_the_post_thumbnail_url( (int) $p->ID, 'thumbnail' ),
			];
		}
		return $out;
	}

	public function get_meal_config( WP_REST_Request $req ): array {
		$product_id = (int) $req->get_param( 'product_id' );
		return [
			'config'  => MealProduct::get_config( $product_id ),
			'addons'  => AddOnMeta::get_addons( $product_id ),
			'bundles' => BundleMeta::get_bundles( $product_id ),
			'price'   => (float) ( wc_get_product( $product_id ) ? wc_get_product( $product_id )->get_price() : 0 ),
		];
	}

	public function get_slots( WP_REST_Request $req ): array {
		$postcode = (string) $req->get_param( 'postcode' );
		$method   = (string) $req->get_param( 'method' );
		$method   = in_array( $method, [ Profile::METHOD_DELIVERY, Profile::METHOD_COLLECTION ], true ) ? $method : null;
		return [ 'options' => SlotAvailability::options( $postcode, $method ) ];
	}
}

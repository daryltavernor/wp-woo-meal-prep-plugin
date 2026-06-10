<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Rest;

use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Checkout\StoreApiExtensions;
use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\BundleMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Stats\PopularCombos;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Error;
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

		register_rest_route(
			'fastnutrition/v1',
			'/cart/add',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ajax_add_to_cart' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'product_id' => [ 'type' => 'integer', 'required' => true ],
					'quantity'   => [ 'type' => 'integer', 'required' => true ],
					'selection'  => [ 'required' => true ],
				],
			]
		);
	}

	public function ajax_add_to_cart( WP_REST_Request $req ): array|WP_Error {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new WP_Error( 'fn_cart_unavailable', __( 'Cart unavailable.', 'fastnutrition-mealprep' ), [ 'status' => 503 ] );
		}

		$product_id = (int) $req->get_param( 'product_id' );
		$quantity   = max( 1, (int) $req->get_param( 'quantity' ) );
		$raw        = $req->get_param( 'selection' );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$raw = is_array( $raw ) ? $raw : [];

		// The existing Selections filter reads $_REQUEST['fn_selection'], so route the data through it.
		$_REQUEST['fn_selection'] = $raw;

		try {
			$added_key = WC()->cart->add_to_cart( $product_id, $quantity );
		} catch ( \Throwable $e ) {
			$added_key = false;
		}

		if ( ! $added_key ) {
			$notices = wc_get_notices( 'error' );
			$message = '';
			if ( is_array( $notices ) && ! empty( $notices ) ) {
				$first   = reset( $notices );
				$message = is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first;
			}
			wc_clear_notices();
			return new WP_Error(
				'fn_cart_add_failed',
				$message !== '' ? $message : __( 'Could not add to cart.', 'fastnutrition-mealprep' ),
				[ 'status' => 400 ]
			);
		}

		WC()->cart->calculate_totals();

		// Build the standard Woo fragments so themes/plugins can update mini-cart, count badges, totals etc.
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', [] );

		return [
			'success'    => true,
			'cart_count' => (int) WC()->cart->get_cart_contents_count(),
			'cart_total' => wp_strip_all_tags( WC()->cart->get_cart_total() ),
			'cart_url'   => wc_get_cart_url(),
			'fragments'  => $fragments,
		];
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

		// get_posts() has already primed the object-term cache for this post
		// type's taxonomies (IngredientType + Allergen) and the ingredient meta,
		// so the term/meta reads below are cache-backed — no per-ingredient query.
		// Featured images aren't primed, so bulk-prime the thumbnail attachments
		// to stop get_the_post_thumbnail_url() loading each one on its own.
		$thumb_ids = [];
		foreach ( $posts as $p ) {
			$tid = (int) get_post_thumbnail_id( (int) $p->ID );
			if ( $tid > 0 ) {
				$thumb_ids[] = $tid;
			}
		}
		if ( ! empty( $thumb_ids ) ) {
			_prime_post_caches( $thumb_ids, false, true );
		}

		$out = [];
		foreach ( $posts as $p ) {
			$id    = (int) $p->ID;
			$out[] = [
				'id'          => $id,
				'name'        => (string) $p->post_title,
				'type'        => self::first_term_slug( $id, IngredientType::TAXONOMY ),
				'tier'        => (string) ( get_post_meta( $id, '_fn_tier', true ) ?: 'standard' ),
				'macros'      => Ingredient::get_macros( $id ),
				'price_delta' => (float) get_post_meta( $id, '_fn_price_delta', true ),
				'allergens'   => self::term_slugs( $id, Allergen::TAXONOMY ),
				'thumbnail'   => (string) get_the_post_thumbnail_url( $id, 'thumbnail' ),
			];
		}
		return $out;
	}

	/** Term slugs for a post in a taxonomy, read from the (get_posts-primed) object cache. */
	private static function term_slugs( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		return array_values( array_map( static fn( $t ) => (string) $t->slug, $terms ) );
	}

	/** First term slug (an ingredient carries exactly one type), or '' when none. */
	private static function first_term_slug( int $post_id, string $taxonomy ): string {
		return self::term_slugs( $post_id, $taxonomy )[0] ?? '';
	}

	public function get_meal_config( WP_REST_Request $req ): array {
		$product_id = (int) $req->get_param( 'product_id' );
		$config     = MealProduct::get_config( $product_id );
		return [
			'config'         => $config,
			'addons'         => AddOnMeta::get_addons( $product_id ),
			'bundles'        => BundleMeta::get_bundles( $product_id ),
			'price'          => (float) ( wc_get_product( $product_id ) ? wc_get_product( $product_id )->get_price() : 0 ),
			// Ranked top combos for the Popular Combinations picker (front end
			// filters to this product's allowed + available ingredients).
			'popular_combos' => ! empty( $config['popular_combos_enabled'] ) ? PopularCombos::ranked_combos() : [],
		];
	}

	public function get_slots( WP_REST_Request $req ): array {
		$postcode = (string) $req->get_param( 'postcode' );
		$method   = (string) $req->get_param( 'method' );
		$method   = in_array( $method, [ Profile::METHOD_DELIVERY, Profile::METHOD_COLLECTION ], true ) ? $method : null;

		// Fees to advertise on the slot picker tabs. Collection is free;
		// delivery is the zone's flat rate for this postcode (null if it's a
		// formula / can't be reduced to a single figure). Pre-format the
		// delivery amount with the store's currency so the UI just prints it.
		$fees           = StoreApiExtensions::fees_for_postcode( $postcode );
		$delivery_label = null;
		if ( null !== $fees['delivery'] && (float) $fees['delivery'] > 0 ) {
			$delivery_label = html_entity_decode( wp_strip_all_tags( wc_price( (float) $fees['delivery'] ) ) );
		}

		return [
			'options' => SlotAvailability::options( $postcode, $method ),
			'fees'    => [ 'delivery' => $delivery_label ],
		];
	}
}

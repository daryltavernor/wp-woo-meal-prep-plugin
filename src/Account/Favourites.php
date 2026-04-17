<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Account;

use FastNutrition\MealPrep\Cart\Selections;
use WP_REST_Request;

final class Favourites {

	public const META_KEY     = 'fn_favourites';
	public const ENDPOINT_KEY = 'fn-favourites';

	public function register(): void {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', [ $this, 'render_endpoint' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT_KEY, EP_ROOT | EP_PAGES );
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT_KEY;
		return $vars;
	}

	public function menu_item( array $items ): array {
		$items[ self::ENDPOINT_KEY ] = __( 'Favourites', 'fastnutrition-mealprep' );
		return $items;
	}

	public function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Please log in to save favourites.', 'fastnutrition-mealprep' ) . '</p>';
			return;
		}
		$list = self::get_list( get_current_user_id() );
		echo '<h2>' . esc_html__( 'Your favourite meals', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $list ) ) {
			echo '<p>' . esc_html__( 'You haven\'t saved any favourites yet. When you build a meal, use the Save Favourite button.', 'fastnutrition-mealprep' ) . '</p>';
			return;
		}
		echo '<table class="woocommerce-table"><thead><tr><th>' . esc_html__( 'Name', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Meal', 'fastnutrition-mealprep' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $list as $fav ) {
			$product = wc_get_product( (int) $fav['product_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $fav['name'] ) . '</td>';
			echo '<td>' . ( $product ? esc_html( $product->get_name() ) : '—' ) . '</td>';
			echo '<td>';
			if ( $product ) {
				$url = add_query_arg(
					[
						'add-to-cart'   => (int) $fav['product_id'],
						'fn_selection'  => rawurlencode( wp_json_encode( $fav['selection'] ) ),
						'quantity'      => 1,
					],
					wc_get_cart_url()
				);
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Reorder', 'fastnutrition-mealprep' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function routes(): void {
		register_rest_route(
			'fastnutrition/v1',
			'/favourites',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'rest_list' ],
					'permission_callback' => static fn() => is_user_logged_in(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rest_save' ],
					'permission_callback' => static fn() => is_user_logged_in(),
					'args'                => [
						'name'       => [ 'type' => 'string', 'required' => true ],
						'product_id' => [ 'type' => 'integer', 'required' => true ],
						'selection'  => [ 'type' => 'object', 'required' => true ],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'rest_delete' ],
					'permission_callback' => static fn() => is_user_logged_in(),
					'args'                => [ 'id' => [ 'type' => 'string', 'required' => true ] ],
				],
			]
		);
	}

	public static function get_list( int $user_id ): array {
		$list = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	public function rest_list(): array {
		return [ 'items' => self::get_list( get_current_user_id() ) ];
	}

	public function rest_save( WP_REST_Request $req ): array {
		$name       = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$product_id = (int) $req->get_param( 'product_id' );
		$selection  = (array) $req->get_param( 'selection' );
		$selection  = Selections::normalize( $product_id, $selection );
		if ( '' === $name || ! $product_id || empty( $selection ) ) {
			return [ 'items' => self::get_list( get_current_user_id() ), 'error' => 'invalid' ];
		}
		$list   = self::get_list( get_current_user_id() );
		$list[] = [
			'id'         => wp_generate_uuid4(),
			'name'       => $name,
			'product_id' => $product_id,
			'selection'  => $selection,
			'created_at' => current_time( 'mysql' ),
		];
		update_user_meta( get_current_user_id(), self::META_KEY, $list );
		return [ 'items' => $list ];
	}

	public function rest_delete( WP_REST_Request $req ): array {
		$id   = sanitize_key( (string) $req->get_param( 'id' ) );
		$list = self::get_list( get_current_user_id() );
		$list = array_values( array_filter( $list, static fn( $f ) => (string) ( $f['id'] ?? '' ) !== $id ) );
		update_user_meta( get_current_user_id(), self::META_KEY, $list );
		return [ 'items' => $list ];
	}
}

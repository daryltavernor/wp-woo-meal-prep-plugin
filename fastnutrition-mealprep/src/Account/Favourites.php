<?php
/**
 * Customer "favourite meal combos" — save a product_id + selection under a name, later reorder with one click.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Account;

use FastNutrition\MealPrep\Cart\Selections;

final class Favourites {

	public const USER_META      = 'fn_favourites';
	public const ENDPOINT       = 'fn-favourites';
	public const NONCE_SAVE     = 'fn_fav_save';
	public const NONCE_REORDER  = 'fn_fav_reorder';
	public const NONCE_DELETE   = 'fn_fav_delete';

	public function register(): void {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );

		add_action( 'template_redirect', [ $this, 'capture_save_from_cart' ] );
		add_action( 'template_redirect', [ $this, 'handle_reorder' ] );
		add_action( 'template_redirect', [ $this, 'handle_delete' ] );

		add_filter( 'woocommerce_get_item_data', [ $this, 'cart_save_button' ], 30, 2 );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function menu_item( array $items ): array {
		$new = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Favourite Meals', 'fastnutrition-mealprep' );
			}
		}
		return $new;
	}

	public function render(): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			echo esc_html__( 'Please log in to save favourites.', 'fastnutrition-mealprep' );
			return;
		}
		$favs = self::get( $uid );
		echo '<h3>' . esc_html__( 'Your saved meals', 'fastnutrition-mealprep' ) . '</h3>';
		if ( empty( $favs ) ) {
			echo '<p>' . esc_html__( 'You have no favourites yet. Save one from the cart on your next order.', 'fastnutrition-mealprep' ) . '</p>';
			return;
		}
		echo '<ul class="fn-favourites-list">';
		foreach ( $favs as $fav ) {
			$reorder_url = wp_nonce_url(
				add_query_arg( [ 'fn_fav_reorder' => $fav['id'] ], wc_get_cart_url() ),
				self::NONCE_REORDER . '_' . $fav['id']
			);
			$delete_url  = wp_nonce_url(
				add_query_arg( [ 'fn_fav_delete' => $fav['id'] ], wc_get_account_endpoint_url( self::ENDPOINT ) ),
				self::NONCE_DELETE . '_' . $fav['id']
			);
			echo '<li><strong>' . esc_html( $fav['name'] ) . '</strong> — ' . esc_html( $fav['summary'] ) . ' — <a href="' . esc_url( $reorder_url ) . '">' . esc_html__( 'Reorder', 'fastnutrition-mealprep' ) . '</a> | <a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Delete?\');">' . esc_html__( 'Delete', 'fastnutrition-mealprep' ) . '</a></li>';
		}
		echo '</ul>';
	}

	public function cart_save_button( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item[ Selections::CART_KEY ] ) || ! is_user_logged_in() ) {
			return $item_data;
		}
		$key = $cart_item['key'] ?? '';
		$url = wp_nonce_url(
			add_query_arg(
				[
					'fn_fav_save'   => 1,
					'fn_cart_key'   => $key,
				],
				wc_get_cart_url()
			),
			self::NONCE_SAVE
		);
		$item_data[] = [
			'name'    => __( 'Save', 'fastnutrition-mealprep' ),
			'display' => '<a href="' . esc_url( $url ) . '" class="fn-save-fav">' . esc_html__( 'Save as favourite', 'fastnutrition-mealprep' ) . '</a>',
		];
		return $item_data;
	}

	public function capture_save_from_cart(): void {
		if ( empty( $_GET['fn_fav_save'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( self::NONCE_SAVE );
		$key = isset( $_GET['fn_cart_key'] ) ? sanitize_text_field( wp_unslash( $_GET['fn_cart_key'] ) ) : '';
		$cart_item = WC()->cart ? ( WC()->cart->get_cart_contents()[ $key ] ?? null ) : null;
		if ( empty( $cart_item ) || empty( $cart_item[ Selections::CART_KEY ] ) ) {
			wc_add_notice( __( 'Could not save favourite.', 'fastnutrition-mealprep' ), 'error' );
			return;
		}
		$name = get_the_title( (int) $cart_item['product_id'] );
		$entry = [
			'id'         => wp_generate_uuid4(),
			'name'       => $name,
			'product_id' => (int) $cart_item['product_id'],
			'selection'  => $cart_item[ Selections::CART_KEY ],
			'summary'    => $this->summarize( $cart_item[ Selections::CART_KEY ] ),
			'created_at' => current_time( 'mysql' ),
		];
		$uid  = get_current_user_id();
		$list = self::get( $uid );
		$list[] = $entry;
		update_user_meta( $uid, self::USER_META, $list );
		wc_add_notice( __( 'Saved to favourites.', 'fastnutrition-mealprep' ) );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	public function handle_reorder(): void {
		if ( empty( $_GET['fn_fav_reorder'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_GET['fn_fav_reorder'] ) );
		check_admin_referer( self::NONCE_REORDER . '_' . $id );
		$uid = get_current_user_id();
		$fav = null;
		foreach ( self::get( $uid ) as $f ) {
			if ( $f['id'] === $id ) {
				$fav = $f;
				break;
			}
		}
		if ( ! $fav ) {
			return;
		}
		// Inject fn_selection so Selections::attach picks it up.
		$_POST['fn_selection'] = wp_json_encode( $fav['selection'] );
		WC()->cart->add_to_cart( (int) $fav['product_id'], 1 );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	public function handle_delete(): void {
		if ( empty( $_GET['fn_fav_delete'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_GET['fn_fav_delete'] ) );
		check_admin_referer( self::NONCE_DELETE . '_' . $id );
		$uid = get_current_user_id();
		$list = array_values( array_filter( self::get( $uid ), static fn( array $f ): bool => $f['id'] !== $id ) );
		update_user_meta( $uid, self::USER_META, $list );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	public static function get( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::USER_META, true );
		return is_array( $raw ) ? $raw : [];
	}

	private function summarize( array $sel ): string {
		if ( 'set' === ( $sel['mode'] ?? '' ) ) {
			return __( 'Set meal', 'fastnutrition-mealprep' );
		}
		$parts = [];
		if ( ! empty( $sel['protein_id'] ) ) {
			$parts[] = get_the_title( (int) $sel['protein_id'] );
		}
		if ( ! empty( $sel['carb_id'] ) ) {
			$parts[] = get_the_title( (int) $sel['carb_id'] );
		}
		foreach ( (array) ( $sel['greens_ids'] ?? [] ) as $gid ) {
			$parts[] = get_the_title( (int) $gid );
		}
		return implode( ' + ', array_filter( $parts ) );
	}
}

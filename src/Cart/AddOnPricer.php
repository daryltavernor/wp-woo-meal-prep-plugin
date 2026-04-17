<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use WC_Cart;

final class AddOnPricer {

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply' ], 10 );
	}

	public function apply( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ Selections::CART_KEY ] ) || empty( $item['data'] ) ) {
				continue;
			}
			$selection = $item[ Selections::CART_KEY ];
			$base      = (float) $item['data']->get_price( 'edit' );
			$delta     = Selections::compute_price_delta( (int) $item['product_id'], is_array( $selection ) ? $selection : [] );
			$item['data']->set_price( $base + $delta );
		}
	}
}

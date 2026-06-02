<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Checkout\StoreApiExtensions;
use WC_Cart;

/**
 * Adds a flat fee to baskets whose subtotal is under a configurable threshold.
 * The fee shows up in the standard WooCommerce cart + checkout totals via
 * cart->add_fee() — no custom rendering of the fee row is needed.
 *
 * The cart UI separately surfaces an inviting message ("Spend £X more to skip
 * the £Y basket surcharge") via TotalsDisplay so the customer is nudged to
 * top up rather than penalised quietly.
 */
final class Surcharge {

	public const OPTION_ENABLED   = 'fn_surcharge_enabled';
	public const OPTION_THRESHOLD = 'fn_surcharge_threshold';
	public const OPTION_AMOUNT    = 'fn_surcharge_amount';
	public const OPTION_LABEL     = 'fn_surcharge_label';

	public function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'maybe_add_fee' ] );
	}

	public function maybe_add_fee( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$status = self::status();
		if ( empty( $status['applies'] ) ) {
			return;
		}
		$cart->add_fee( self::label(), (float) $status['amount'], true );
	}

	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '1' );
	}

	public static function threshold(): float {
		return (float) get_option( self::OPTION_THRESHOLD, '23' );
	}

	public static function amount(): float {
		return (float) get_option( self::OPTION_AMOUNT, '8' );
	}

	public static function label(): string {
		$stored = (string) get_option( self::OPTION_LABEL, '' );
		return '' !== $stored ? $stored : __( 'Basket surcharge', 'fastnutrition-mealprep' );
	}

	/**
	 * Snapshot describing whether the surcharge currently applies, used by the
	 * cart UI to render a friendly "spend X more to skip this fee" hint.
	 *
	 * @return array{enabled:bool,threshold:float,amount:float,label:string,subtotal:float,applies:bool,remaining:float}
	 */
	public static function status(): array {
		$threshold = self::threshold();
		$amount    = self::amount();
		$label     = self::label();
		$enabled   = self::enabled();

		$subtotal = 0.0;
		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			$subtotal = (float) WC()->cart->get_subtotal();
		}

		// The surcharge is a DELIVERY charge: it only applies once the customer
		// has chosen a delivery slot at checkout. It never applies to collection,
		// and not on the basket page / step 1 where no fulfilment is set yet.
		$is_delivery = 'delivery' === ( StoreApiExtensions::get_session_fulfilment()['type'] ?? '' );

		$applies = $enabled
			&& $is_delivery
			&& $threshold > 0
			&& $amount > 0
			&& $subtotal > 0
			&& $subtotal < $threshold;

		return [
			'enabled'     => $enabled,
			'threshold'   => $threshold,
			'amount'      => $amount,
			'label'       => $label,
			'subtotal'    => $subtotal,
			'applies'     => $applies,
			'is_delivery' => $is_delivery,
			'remaining'   => max( 0.0, $threshold - $subtotal ),
		];
	}
}

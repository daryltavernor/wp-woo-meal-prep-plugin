<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Delivery;

use WC_Cart;

/**
 * "Minimum order for delivery" rule.
 *
 * When set, orders whose FOOD subtotal (cart line items only — excluding the
 * delivery charge, the basket surcharge fee, tax and coupon discounts) is under
 * the threshold cannot use delivery: only collection is offered. 0 (the default)
 * disables the rule.
 *
 * Enforced server-side (slot selection, shipping rates, order commit) and
 * surfaced to the checkout slot picker via the cart extension data so the
 * Delivery tab hides itself live as the basket changes.
 */
final class DeliveryThreshold {

	public const OPTION = 'fn_delivery_min_subtotal';

	/** The configured minimum, or 0.0 when the rule is off. */
	public static function min(): float {
		return max( 0.0, (float) get_option( self::OPTION, 0 ) );
	}

	/**
	 * The food-only subtotal of the current cart: the sum of line item
	 * subtotals (pre-discount, ex tax), which excludes shipping, the surcharge
	 * fee and tax. Null when there is no cart context (fail open).
	 */
	public static function cart_items_total(): ?float {
		if ( ! function_exists( 'WC' ) || ! ( WC()->cart instanceof WC_Cart ) ) {
			return null;
		}
		$total = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$total += (float) ( $item['line_subtotal'] ?? 0 );
		}
		return $total;
	}

	/** Whether delivery is allowed for the current cart. Fails open when unknown. */
	public static function delivery_allowed(): bool {
		return self::is_allowed( self::min(), self::cart_items_total() );
	}

	/**
	 * Pure allow-decision: delivery is allowed when the rule is off ($min <= 0),
	 * the cart total is unknown (fail open), or the food subtotal meets the
	 * minimum (with a small epsilon so an exact-threshold basket qualifies).
	 */
	public static function is_allowed( float $min, ?float $total ): bool {
		if ( $min <= 0.0 || null === $total ) {
			return true;
		}
		return $total + 0.0001 >= $min;
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Products\MealProduct;
use WC_Order_Item_Product;

/**
 * Carries a meal's composition over when a customer uses WooCommerce's
 * "Order again" feature.
 *
 * WooCommerce re-adds each order line item to the cart but, by default, drops
 * our `_fn_selection` (the protein/carb/greens, set meal or standalone item,
 * plus add-ons), leaving a bare meal product. We restore the selection from the
 * order line item via woocommerce_order_again_cart_item_data, re-normalised
 * against the product's CURRENT config and availability:
 *
 *   - Add-ons are re-priced / dropped to match the product today.
 *   - Lines whose ingredient / item is no longer published or active are NOT
 *     re-added; the customer gets one combined notice listing what to rebuild.
 *   - Everything else (line pricing, ingredient deltas, bundle tiers, the cart
 *     display) flows through the existing pipeline unchanged, at today's prices.
 *
 * Bundles need no special handling: BundlePricer recomputes them from cart
 * quantities, so re-adding the lines restores the bundle discount automatically.
 */
final class Reorder {

	/** True while a WooCommerce "Order again" is repopulating the cart. */
	private static bool $active = false;

	/** Product names that couldn't be reconstructed during the current reorder. */
	private static array $skipped = [];

	public function register(): void {
		add_filter( 'woocommerce_order_again_cart_item_data', [ $this, 'restore_selection' ], 10, 3 );
		add_action( 'woocommerce_ordered_again', [ $this, 'finish' ] );
	}

	/** True while an "Order again" is in progress (read by Selections::validate). */
	public static function is_active(): bool {
		return self::$active;
	}

	/** Record a product whose meal couldn't be reconstructed, for the summary notice. */
	public static function record_skipped( int $product_id ): void {
		if ( self::$active ) {
			self::$skipped[] = get_the_title( $product_id );
		}
	}

	/**
	 * Re-attach the stored selection to the cart item being re-added.
	 *
	 * @param array $cart_item_data
	 * @param mixed $item  Expected WC_Order_Item_Product.
	 * @param mixed $order Unused.
	 * @return array
	 */
	public function restore_selection( array $cart_item_data, $item, $order ): array {
		unset( $order );
		self::$active = true; // Set for every item so later meal lines validate quietly.

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $cart_item_data;
		}
		$product_id = (int) $item->get_product_id();
		if ( ! MealProduct::is_configurable( $product_id ) ) {
			return $cart_item_data; // Plain product — leave WC to re-add as normal.
		}

		$stored = $item->get_meta( '_fn_selection', true );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			// Order placed before meal selections were stored. Leave the bare
			// product; validation declines it and records it as skipped.
			return $cart_item_data;
		}

		// Re-normalise against the live config (drops disallowed ingredients,
		// re-prices/strips add-ons), then confirm everything is still available.
		$selection = Selections::normalize( $product_id, $stored );
		if ( empty( $selection ) || ! self::selection_available( $selection ) ) {
			return $cart_item_data; // Declined + recorded during validation.
		}

		$cart_item_data[ Selections::CART_KEY ]           = $selection;
		$cart_item_data[ Selections::CART_KEY . '_hash' ] = md5( (string) wp_json_encode( $selection ) );
		return $cart_item_data;
	}

	/**
	 * Every ingredient the selection references must still be published and
	 * active — matching the customer-facing catalogue (RestController::
	 * get_ingredients treats a missing _fn_active meta as active).
	 */
	private static function selection_available( array $selection ): bool {
		foreach ( Selection::ingredient_ids( $selection ) as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				return false;
			}
			if ( metadata_exists( 'post', $id, '_fn_active' ) && '1' !== (string) get_post_meta( $id, '_fn_active', true ) ) {
				return false;
			}
		}
		return true;
	}

	/** After the reorder, surface any skipped meals in a single notice and reset. */
	public function finish( $order_id ): void {
		unset( $order_id );
		$names = array_values( array_unique( array_filter( self::$skipped ) ) );
		if ( ! empty( $names ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: comma-separated list of product names */
					__( 'Some items from your previous order are no longer available and were not added: %s. Please rebuild them to order again.', 'fastnutrition-mealprep' ),
					implode( ', ', $names )
				),
				'notice'
			);
		}
		self::$active  = false;
		self::$skipped = [];
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

use FastNutrition\MealPrep\Cart\MealPricing;
use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Checkout\StoreApiExtensions;
use FastNutrition\MealPrep\Delivery\BlockedDates;
use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Products\MealProduct;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WP_Error;

/**
 * The reusable "create order from selection" used by the In-Store Quick Order
 * tool. Builds a real WooCommerce order with WC's internal PHP functions — never
 * the public REST/Store API — so offline orders are identical to online ones for
 * meal composition, pricing and fulfilment metadata.
 *
 * Parity is guaranteed by calling the SAME shared code the online checkout uses:
 *  - MealPricing::price_lines()        — line pricing + bundle apportionment.
 *  - OrderItemMeta::write_line_meta()  — meal-composition + macros line meta.
 *  - _fn_fulfilment in the exact shape StoreApiExtensions writes online.
 *
 * Per product decision the offline tool charges the delivery fee (delivery only)
 * but NOT the basket surcharge, and maps paid → completed, unpaid → on-hold.
 */
final class OrderFactory {

	/** Email ids suppressed when the confirmation toggle is off / no email given. */
	private const ORDER_EMAIL_IDS = [
		'new_order',
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_invoice',
	];

	/**
	 * @param array $payload {
	 *   lines:      array<int,array{product_id:int,quantity:int,selection:array}>,
	 *   customer:   array{phone:string,email?:string,first_name?:string,last_name?:string,
	 *                     address_1?:string,address_2?:string,city?:string,postcode?:string,
	 *                     state?:string,country?:string},
	 *   fulfilment: array{type:string,profile_id:int,date:string,slot:array{start:string,end:string}},
	 *   payment:    string,
	 *   paid:       bool,
	 *   send_email: bool,
	 *   staff:      array{id:int,name:string},
	 * }
	 * @return WC_Order|WP_Error
	 */
	public static function create( array $payload ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'fn_wc_unavailable', __( 'WooCommerce is not available.', 'fastnutrition-mealprep' ), [ 'status' => 503 ] );
		}

		// 1. Validate + normalise the lines (never trust the client).
		$normalised = self::normalise_lines( $payload['lines'] ?? [] );
		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

		// 2. Validate fulfilment up front so we don't create a half-order.
		$fulfilment = self::validate_fulfilment( $payload['fulfilment'] ?? [] );
		if ( is_wp_error( $fulfilment ) ) {
			return $fulfilment;
		}

		$customer   = (array) ( $payload['customer'] ?? [] );
		$first_name = sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) );
		if ( '' === trim( $first_name ) || '' === trim( $last_name ) ) {
			return new WP_Error( 'fn_name_required', __( 'A first and last name are required.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		$phone = sanitize_text_field( (string) ( $customer['phone'] ?? '' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'fn_phone_required', __( 'A phone number is required.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		$email      = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$is_delivery = ( Profile::METHOD_DELIVERY === $fulfilment['type'] );

		if ( $is_delivery && '' === trim( (string) ( $customer['address_1'] ?? '' ) ) ) {
			return new WP_Error( 'fn_address_required', __( 'A delivery address is required.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}

		// Payment: a method is only required when the order is marked paid. An
		// unpaid order is "cash on collection/delivery" — recorded with a clear
		// title and left to be settled (status maps to On hold below).
		$paid = ! empty( $payload['paid'] );
		if ( $paid ) {
			$payment = (string) ( $payload['payment'] ?? '' );
			if ( ! isset( InStoreSettings::PAYMENT_METHODS[ $payment ] ) ) {
				return new WP_Error( 'fn_payment_invalid', __( 'Choose a payment method.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
			}
			$method_slug  = $payment;
			$method_title = InStoreSettings::payment_label( $payment );
		} else {
			$method_slug  = 'cod';
			$method_title = $is_delivery
				? __( 'Cash on delivery', 'fastnutrition-mealprep' )
				: __( 'Cash on collection', 'fastnutrition-mealprep' );
		}

		// 3. Create the order shell.
		$order = wc_create_order( [ 'created_via' => 'fn_instore' ] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// 4. Price + add the line items through the shared pipeline.
		$priced = MealPricing::price_lines( $normalised );
		foreach ( $normalised as $key => $line ) {
			$product = wc_get_product( (int) $line['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$qty  = (int) $line['quantity'];
			$unit = (float) ( $priced[ $key ]['unit_price'] ?? $product->get_price( 'edit' ) );

			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $qty );
			$item->set_subtotal( $unit * $qty );
			$item->set_total( $unit * $qty );
			OrderItemMeta::write_line_meta( $item, (int) $line['product_id'], $line['selection'] );
			$order->add_item( $item );
		}

		// 5. Addresses + contact.
		$billing = [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => $phone,
			'email'      => $email,
			'address_1'  => sanitize_text_field( (string) ( $customer['address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $customer['address_2'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $customer['city'] ?? '' ) ),
			'state'      => sanitize_text_field( (string) ( $customer['state'] ?? '' ) ),
			'postcode'   => sanitize_text_field( (string) ( $customer['postcode'] ?? '' ) ),
			'country'    => self::resolve_country( (string) ( $customer['country'] ?? '' ) ),
		];
		$order->set_address( $billing, 'billing' );
		if ( $is_delivery ) {
			$shipping = $billing;
			unset( $shipping['email'] );
			$order->set_address( $shipping, 'shipping' );
		}

		// 6. Fulfilment meta — identical shape to the online checkout.
		$order->update_meta_data( '_fn_fulfilment', $fulfilment );

		// 7. Delivery fee only (no basket surcharge), delivery orders only.
		if ( $is_delivery ) {
			self::maybe_add_delivery_fee( $order, $billing['postcode'], (int) $fulfilment['profile_id'] );
		}

		// 8. Payment.
		$order->set_payment_method( $method_slug );
		$order->set_payment_method_title( $method_title );

		// 9. Offline + staff attribution tags.
		$staff = (array) ( $payload['staff'] ?? [] );
		$order->update_meta_data( '_fn_offline_order', 'yes' );
		$order->update_meta_data( '_fn_staff_name', sanitize_text_field( (string) ( $staff['name'] ?? '' ) ) );
		$order->update_meta_data( '_fn_staff_id', (int) ( $staff['id'] ?? 0 ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: staff name, 2: paid/unpaid, 3: payment method label */
				__( 'In-store order taken by %1$s. %2$s — %3$s.', 'fastnutrition-mealprep' ),
				(string) ( $staff['name'] ?? '—' ),
				$paid ? __( 'Paid', 'fastnutrition-mealprep' ) : __( 'Not paid', 'fastnutrition-mealprep' ),
				$method_title
			)
		);

		// 10. Totals, status + email handling.
		$order->calculate_totals();

		$status     = $paid ? InStoreSettings::STATUS_PAID : InStoreSettings::STATUS_UNPAID;
		$send_email = ! empty( $payload['send_email'] ) && '' !== $email;

		if ( $paid ) {
			$order->set_date_paid( time() );
		}

		// Setting the status here means the first save() runs the transition,
		// which reduces stock (matching the online flow). We ALWAYS suppress the
		// automatic transactional-email cascade for offline orders, because the
		// "confirmation email" is an explicit per-order choice — and several of
		// these statuses (e.g. on hold for unpaid) have no customer email at all.
		// When requested we then send the customer the order-details email
		// directly, which works regardless of status.
		$order->set_status( $status, sprintf( __( 'In-store order (%s).', 'fastnutrition-mealprep' ), $paid ? __( 'paid', 'fastnutrition-mealprep' ) : __( 'unpaid', 'fastnutrition-mealprep' ) ) );

		self::suppress_order_emails( true );
		$order->save();
		self::suppress_order_emails( false );

		if ( $send_email && function_exists( 'WC' ) && WC() && WC()->mailer() ) {
			// The order-details ("invoice") email is the canonical on-demand
			// confirmation. Delivery depends on the site's mail transport — e.g.
			// Post SMTP — being configured; this only hands the mail to wp_mail().
			WC()->mailer()->customer_invoice( $order );
		}

		return $order;
	}

	/**
	 * Re-validate every line server-side via Selections::normalize(); reject the
	 * whole order if any line is empty/invalid.
	 *
	 * @return array<int,array{product_id:int,quantity:int,selection:array}>|WP_Error
	 */
	private static function normalise_lines( $lines ) {
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return new WP_Error( 'fn_no_lines', __( 'The order is empty.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		$out = [];
		foreach ( array_values( $lines ) as $i => $line ) {
			$pid = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
			$qty = isset( $line['quantity'] ) ? max( 1, (int) $line['quantity'] ) : 1;
			if ( ! $pid || ! MealProduct::is_meal( $pid ) ) {
				return new WP_Error( 'fn_line_invalid', __( 'One of the items is not a valid meal product.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
			}
			$raw       = isset( $line['selection'] ) && is_array( $line['selection'] ) ? $line['selection'] : [];
			$selection = Selections::normalize( $pid, $raw );
			if ( empty( $selection ) ) {
				return new WP_Error( 'fn_selection_invalid', __( 'One of the items has an invalid selection.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
			}
			$out[ $i ] = [
				'product_id' => $pid,
				'quantity'   => $qty,
				'selection'  => $selection,
			];
		}
		return $out;
	}

	/**
	 * Validate the chosen slot the same way the online Store API update does.
	 *
	 * @return array{type:string,profile_id:int,date:string,slot:array{start:string,end:string}}|WP_Error
	 */
	private static function validate_fulfilment( $payload ) {
		$payload = is_array( $payload ) ? $payload : [];
		$type    = in_array( $payload['type'] ?? '', [ Profile::METHOD_DELIVERY, Profile::METHOD_COLLECTION ], true ) ? (string) $payload['type'] : '';
		$pid     = isset( $payload['profile_id'] ) ? (int) $payload['profile_id'] : 0;
		$date    = isset( $payload['date'] ) ? sanitize_text_field( (string) $payload['date'] ) : '';
		$slot    = [
			'start' => isset( $payload['slot']['start'] ) ? sanitize_text_field( (string) $payload['slot']['start'] ) : '',
			'end'   => isset( $payload['slot']['end'] ) ? sanitize_text_field( (string) $payload['slot']['end'] ) : '',
		];

		if ( '' === $type || ! $pid || '' === $date ) {
			return new WP_Error( 'fn_fulfilment_required', __( 'Choose a delivery or collection slot.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		$profile = Profile::get( $pid );
		if ( ! $profile || $profile['method'] !== $type ) {
			return new WP_Error( 'fn_fulfilment_invalid', __( 'That slot is no longer available.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		if ( BlockedDates::is_blocked( $date ) || $date < SlotAvailability::earliest_allowed_date() ) {
			return new WP_Error( 'fn_fulfilment_date', __( 'That date can no longer be selected.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}
		return [
			'type'       => $type,
			'profile_id' => $pid,
			'date'       => $date,
			'slot'       => $slot,
		];
	}

	/**
	 * Add a shipping line equal to the postcode's WC zone delivery rate, matching
	 * what an online delivery order would be charged. Skipped when the zone has no
	 * single flat figure (e.g. a formula rate) so we never guess.
	 */
	private static function maybe_add_delivery_fee( WC_Order $order, string $postcode, int $profile_id ): void {
		$fees = StoreApiExtensions::fees_for_postcode( $postcode );
		$cost = $fees['delivery'] ?? null;
		if ( null === $cost || (float) $cost <= 0 ) {
			return;
		}
		$label   = __( 'Delivery', 'fastnutrition-mealprep' );
		$profile = $profile_id ? Profile::get( $profile_id ) : null;
		if ( $profile && '' !== (string) $profile['name'] ) {
			$label = (string) $profile['name'];
		}
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( $label );
		$shipping->set_method_id( 'fn_instore_delivery' );
		$shipping->set_total( (float) $cost );
		$order->add_item( $shipping );
	}

	private static function resolve_country( string $country ): string {
		$country = strtoupper( trim( $country ) );
		if ( '' !== $country ) {
			return $country;
		}
		if ( function_exists( 'wc_get_base_location' ) ) {
			$base = wc_get_base_location();
			return (string) ( $base['country'] ?? 'GB' );
		}
		return 'GB';
	}

	/** Toggle suppression of the order-related transactional emails. */
	private static function suppress_order_emails( bool $on ): void {
		foreach ( self::ORDER_EMAIL_IDS as $id ) {
			if ( $on ) {
				add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 99 );
			} else {
				remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 99 );
			}
		}
	}
}

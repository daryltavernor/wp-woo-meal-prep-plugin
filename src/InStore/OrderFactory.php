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

		$prep = self::prepare( $payload );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}

		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		self::apply( $order, $prep );

		// We ALWAYS suppress the automatic transactional-email cascade for
		// offline orders, because the "confirmation email" is an explicit
		// per-order choice — and several of these statuses (e.g. on hold for
		// unpaid) have no customer email at all. When requested we then send the
		// customer the order-details email directly, which works for any status.
		self::suppress_order_emails( true );
		$order->save();
		self::suppress_order_emails( false );

		self::maybe_note_status_mismatch( $order, (string) $prep['status'] );

		if ( $prep['send_email'] && function_exists( 'WC' ) && WC() && WC()->mailer() ) {
			// Delivery depends on the site's mail transport — e.g. Post SMTP —
			// being configured; this only hands the mail to wp_mail().
			WC()->mailer()->customer_invoice( $order );
		}

		return $order;
	}

	/**
	 * Diagnostic: if WooCommerce didn't keep the status we set (e.g. it reverted
	 * to "pending"), record both the intended and actual status as an order note,
	 * so the cause is visible instead of failing silently. Helps pin down
	 * environment-specific status-handling issues (HPOS, custom statuses, etc.).
	 */
	private static function maybe_note_status_mismatch( WC_Order $order, string $expected ): void {
		if ( ! $order->get_id() || $expected === $order->get_status() ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: intended order status, 2: status WooCommerce actually saved */
				__( 'FN diagnostic: order was set to "%1$s" but WooCommerce saved it as "%2$s".', 'fastnutrition-mealprep' ),
				$expected,
				$order->get_status()
			)
		);
		$order->save();
	}

	/**
	 * Create a persisted "Prep / label only" order from the same payload.
	 *
	 * Used by the Quick Label Maker when staff opt to add the batch to the prep
	 * sheet. It is a real order so it flows through the prep totals, pick list,
	 * slot capacity and label reprints — but it is parked in the non-sales
	 * PrepOrderStatus, so it never counts toward revenue/analytics, reduces no
	 * stock and sends no emails. The paid/unpaid choice is still honoured purely
	 * so the label's PAID/UNPAID block renders correctly.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function create_prep_order( array $payload ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'fn_wc_unavailable', __( 'WooCommerce is not available.', 'fastnutrition-mealprep' ), [ 'status' => 503 ] );
		}
		$prep = self::prepare( $payload );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		// Park it in the prep-only status regardless of the paid/unpaid mapping.
		$prep['status']    = PrepOrderStatus::STATUS;
		$prep['prep_only'] = true;

		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		self::apply( $order, $prep );

		self::suppress_order_emails( true );
		$order->save();
		self::suppress_order_emails( false );

		self::maybe_note_status_mismatch( $order, PrepOrderStatus::STATUS );

		return $order;
	}

	/**
	 * Build an in-memory (unsaved) order from the same payload, for the Quick
	 * Label Maker. It is never persisted — it exists only so the label renderer
	 * can produce identical labels (including the payment block) without
	 * creating a WooCommerce order.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function assemble_for_labels( array $payload ) {
		if ( ! class_exists( WC_Order::class ) ) {
			return new WP_Error( 'fn_wc_unavailable', __( 'WooCommerce is not available.', 'fastnutrition-mealprep' ), [ 'status' => 503 ] );
		}
		$prep = self::prepare( $payload );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		$order = new WC_Order();
		self::apply( $order, $prep );
		return $order;
	}

	/**
	 * Validate the payload and return a normalised build spec, or a WP_Error.
	 * Phone is optional; first + last name are required.
	 *
	 * @return array|WP_Error
	 */
	private static function prepare( array $payload ) {
		$normalised = self::normalise_lines( $payload['lines'] ?? [] );
		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

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
		$email       = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		$is_delivery = ( Profile::METHOD_DELIVERY === $fulfilment['type'] );

		if ( $is_delivery && '' === trim( (string) ( $customer['address_1'] ?? '' ) ) ) {
			return new WP_Error( 'fn_address_required', __( 'A delivery address is required.', 'fastnutrition-mealprep' ), [ 'status' => 400 ] );
		}

		// A payment method is only required when marked paid. An unpaid order is
		// "cash on collection/delivery" — recorded with a clear title.
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

		$billing = [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
			'email'      => $email,
			'address_1'  => sanitize_text_field( (string) ( $customer['address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $customer['address_2'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $customer['city'] ?? '' ) ),
			'state'      => sanitize_text_field( (string) ( $customer['state'] ?? '' ) ),
			'postcode'   => sanitize_text_field( (string) ( $customer['postcode'] ?? '' ) ),
			'country'    => self::resolve_country( (string) ( $customer['country'] ?? '' ) ),
		];

		return [
			'lines'        => $normalised,
			'fulfilment'   => $fulfilment,
			'billing'      => $billing,
			'is_delivery'  => $is_delivery,
			'paid'         => $paid,
			'method_slug'  => $method_slug,
			'method_title' => $method_title,
			'status'       => $paid ? InStoreSettings::STATUS_PAID : InStoreSettings::STATUS_UNPAID,
			'email'        => $email,
			'send_email'   => ! empty( $payload['send_email'] ) && '' !== $email,
			'staff'        => (array) ( $payload['staff'] ?? [] ),
		];
	}

	/**
	 * Populate an order object from a prepared spec. Does NOT save — the caller
	 * decides whether to persist (create) or keep it in memory (label maker).
	 */
	private static function apply( WC_Order $order, array $prep ): void {
		$order->set_created_via( 'fn_instore' );

		// Line items, priced through the shared bundle pipeline.
		$priced = MealPricing::price_lines( $prep['lines'] );
		foreach ( $prep['lines'] as $key => $line ) {
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

		// Addresses + contact.
		$billing = $prep['billing'];
		$order->set_address( $billing, 'billing' );
		if ( $prep['is_delivery'] ) {
			$shipping = $billing;
			unset( $shipping['email'] );
			$order->set_address( $shipping, 'shipping' );
		}

		// Fulfilment meta — identical shape to the online checkout.
		$order->update_meta_data( '_fn_fulfilment', $prep['fulfilment'] );

		// Delivery fee only (no basket surcharge), delivery orders only.
		if ( $prep['is_delivery'] ) {
			self::maybe_add_delivery_fee( $order, $billing['postcode'], (int) $prep['fulfilment']['profile_id'] );
		}

		// Payment.
		$order->set_payment_method( $prep['method_slug'] );
		$order->set_payment_method_title( $prep['method_title'] );

		// Offline + staff attribution tags.
		$staff = $prep['staff'];
		$order->update_meta_data( '_fn_offline_order', 'yes' );
		$order->update_meta_data( '_fn_staff_name', sanitize_text_field( (string) ( $staff['name'] ?? '' ) ) );
		$order->update_meta_data( '_fn_staff_id', (int) ( $staff['id'] ?? 0 ) );
		if ( ! empty( $prep['prep_only'] ) ) {
			$order->update_meta_data( '_fn_prep_only', 'yes' );
		}

		// Totals + status (in memory; the caller saves if persisting).
		$order->calculate_totals();
		if ( $prep['paid'] ) {
			$order->set_date_paid( time() );
		}
		$is_prep = ! empty( $prep['prep_only'] );
		$order->set_status(
			$prep['status'],
			$is_prep
				? __( 'Quick Label Maker — prep / label only (not a sale).', 'fastnutrition-mealprep' )
				: sprintf( __( 'In-store order (%s).', 'fastnutrition-mealprep' ), $prep['paid'] ? __( 'paid', 'fastnutrition-mealprep' ) : __( 'unpaid', 'fastnutrition-mealprep' ) )
		);

		// An order note needs a persisted order id; only add it for real orders.
		if ( $order->get_id() ) {
			$order->add_order_note(
				$is_prep
					? sprintf(
						/* translators: %s: staff member */
						__( 'PREP / LABEL ONLY — created by %s via the Quick Label Maker. Excluded from sales; feeds the prep sheet only.', 'fastnutrition-mealprep' ),
						(string) ( $staff['name'] ?? '—' )
					)
					: sprintf(
						/* translators: 1: staff name, 2: paid/unpaid, 3: payment method label */
						__( 'In-store order taken by %1$s. %2$s — %3$s.', 'fastnutrition-mealprep' ),
						(string) ( $staff['name'] ?? '—' ),
						$prep['paid'] ? __( 'Paid', 'fastnutrition-mealprep' ) : __( 'Not paid', 'fastnutrition-mealprep' ),
						$prep['method_title']
					)
			);
		}
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
			if ( ! $pid || ! MealProduct::is_configurable( $pid ) ) {
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
		// In-store staff take orders all evening, so the cut-off is relaxed to
		// 23:55 here (the public website keeps its configured cut-off).
		if ( BlockedDates::is_blocked( $date ) || $date < SlotAvailability::earliest_allowed_date( InStoreSettings::INSTORE_CUTOFF ) ) {
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

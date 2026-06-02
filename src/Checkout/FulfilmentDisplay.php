<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

use FastNutrition\MealPrep\Delivery\Profile;
use WC_Order;

/**
 * Renders the chosen delivery/collection slot — method, date and time window —
 * as a single clear line wherever the customer or the team sees the order:
 *
 *   - the order totals table (thank-you page, My Account → order, and BOTH the
 *     customer and admin "New order" emails, which all render the same totals),
 *   - the admin order edit screen, under the shipping address.
 *
 * The fulfilment is persisted to the order as `_fn_fulfilment` by
 * StoreApiExtensions::apply_to_order(). This class is display-only.
 */
final class FulfilmentDisplay {

	public function register(): void {
		// One row in the order totals table covers the thank-you page, the
		// My Account order view and every email that prints order totals.
		add_filter( 'woocommerce_get_order_item_totals', [ $this, 'add_totals_row' ], 20, 3 );
		// Admin order edit screen — a clear block under the shipping address.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'render_admin_block' ], 10, 1 );
		// Admin order edit screen — also surface the slot in the line-items
		// table, under the shipping line, where the order is actually read.
		add_action( 'woocommerce_after_order_itemmeta', [ $this, 'render_admin_line_item' ], 10, 3 );
		// Customer-facing collection address + directions + opening-times notice:
		// on the order details page (thank-you / My Account) and in the customer
		// emails. Only shown for collection orders.
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_collection_location_details' ], 10, 1 );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_collection_location_email' ], 20, 4 );
	}

	/**
	 * The pickup business name. Filterable so the address can be changed without
	 * editing code (e.g. add_filter( 'fn_collection_business_name', … )).
	 */
	public static function collection_business(): string {
		return (string) apply_filters( 'fn_collection_business_name', 'Fast Nutrition' );
	}

	/** The pickup street address (single line). */
	public static function collection_address(): string {
		return (string) apply_filters( 'fn_collection_address', '141 London Rd, Chesterton, Newcastle ST5 7JD' );
	}

	/** A Google Maps directions link to the pickup point. */
	public static function collection_map_url(): string {
		$default = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( self::collection_business() . ', ' . self::collection_address() );
		return (string) apply_filters( 'fn_collection_map_url', $default );
	}

	/** Opening-times disclaimer shown beneath the collection address. */
	public static function collection_notice(): string {
		return (string) apply_filters(
			'fn_collection_notice',
			__( 'Please be sure to check our opening times on the website, our Google business page and social media for any updates before collecting.', 'fastnutrition-mealprep' )
		);
	}

	/**
	 * Build a normalised summary of the order's fulfilment, or [] if none.
	 *
	 * @return array{type:string,method_label:string,date_label:string,time_label:string,location:string,profile_name:string,customer_value:string,admin_value:string}|array{}
	 */
	public static function summary( WC_Order $order ): array {
		$ff = $order->get_meta( '_fn_fulfilment' );
		if ( ! is_array( $ff ) || empty( $ff['type'] ) ) {
			return [];
		}

		$type         = (string) $ff['type'];
		$is_collection = ( 'collection' === $type );
		$method_label = $is_collection
			? __( 'Collection', 'fastnutrition-mealprep' )
			: __( 'Delivery', 'fastnutrition-mealprep' );

		$date       = (string) ( $ff['date'] ?? '' );
		// Anchor at midday so a site timezone offset can't shift the displayed day.
		$date_label = $date ? wp_date( 'l, j F Y', strtotime( $date . ' 12:00:00' ) ) : '';

		$slot       = is_array( $ff['slot'] ?? null ) ? $ff['slot'] : [];
		$start      = trim( (string) ( $slot['start'] ?? '' ) );
		$end        = trim( (string) ( $slot['end'] ?? '' ) );
		$time_label = ( '' !== $start && '' !== $end ) ? ( $start . '–' . $end ) : $start;

		$profile_name = '';
		if ( ! empty( $ff['profile_id'] ) ) {
			$profile = Profile::get( (int) $ff['profile_id'] );
			if ( $profile ) {
				$profile_name = (string) $profile['name'];
			}
		}

		// Customer-facing value: date + time. For collection, name the actual
		// pickup business (the delivery-profile name — e.g. "Collections" — is
		// internal jargon and reads as nonsense like "Collect from Collections").
		// For delivery the round/area name is left for the admin view only.
		$customer_parts = array_filter( [ $date_label, $time_label ] );
		$customer_value = implode( ' · ', $customer_parts );
		if ( $is_collection ) {
			/* translators: %s: pickup business name */
			$collect        = sprintf( __( 'Collect from %s', 'fastnutrition-mealprep' ), self::collection_business() );
			$customer_value = '' !== $customer_value ? $customer_value . ' · ' . $collect : $collect;
		}

		// Admin value: date + time, plus the delivery round (delivery only) or
		// the pickup business name (collection).
		$admin_tail  = $is_collection
			? sprintf( __( 'Collect from %s', 'fastnutrition-mealprep' ), self::collection_business() )
			: $profile_name;
		$admin_parts = array_filter( [ $date_label, $time_label, $admin_tail ] );
		$admin_value = implode( ' · ', $admin_parts );

		return [
			'type'           => $type,
			'method_label'   => $method_label,
			'date_label'     => $date_label,
			'time_label'     => $time_label,
			'location'       => $is_collection ? $profile_name : '',
			'profile_name'   => $profile_name,
			'customer_value' => $customer_value,
			'admin_value'    => $admin_value,
		];
	}

	/**
	 * Insert a clear "Delivery: <date · time>" / "Collection: <…>" row into the
	 * order totals table, right after the shipping line.
	 *
	 * @param array<string,array{label:string,value:string}> $rows
	 * @param WC_Order                                        $order
	 * @param string                                          $tax_display
	 * @return array<string,array{label:string,value:string}>
	 */
	public function add_totals_row( array $rows, $order, $tax_display = '' ): array {
		unset( $tax_display );
		if ( ! $order instanceof WC_Order ) {
			return $rows;
		}
		$data = self::summary( $order );
		if ( empty( $data ) || '' === $data['customer_value'] ) {
			return $rows;
		}

		$entry = [
			'label' => $data['method_label'] . ':',
			'value' => esc_html( $data['customer_value'] ),
		];

		// Place it immediately after the shipping row; fall back to just before
		// the order total, then to the end.
		$out      = [];
		$inserted = false;
		foreach ( $rows as $key => $row ) {
			if ( 'order_total' === $key && ! $inserted ) {
				$out['fn_fulfilment'] = $entry;
				$inserted             = true;
			}
			$out[ $key ] = $row;
			if ( 'shipping' === $key && ! $inserted ) {
				$out['fn_fulfilment'] = $entry;
				$inserted             = true;
			}
		}
		if ( ! $inserted ) {
			$out['fn_fulfilment'] = $entry;
		}

		return $out;
	}

	/**
	 * Clear fulfilment block on the admin order edit screen.
	 *
	 * @param WC_Order $order
	 */
	public function render_admin_block( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$data = self::summary( $order );
		if ( empty( $data ) ) {
			return;
		}
		echo '<div class="fn-admin-fulfilment" style="margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:3px">';
		echo '<p style="margin:0 0 4px;text-transform:uppercase;font-size:11px;letter-spacing:.04em;color:#646970">' . esc_html__( 'Delivery / Collection', 'fastnutrition-mealprep' ) . '</p>';
		echo '<p style="margin:0;font-size:14px"><strong>' . esc_html( $data['method_label'] ) . '</strong>';
		if ( '' !== $data['admin_value'] ) {
			echo ' — ' . esc_html( $data['admin_value'] );
		}
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Render the slot under the shipping line in the admin order items table.
	 *
	 * `woocommerce_after_order_itemmeta` fires inside every order-item row in
	 * the admin items meta box. We only render on the shipping line so it shows
	 * once, right under WooCommerce's package-contents list, rather than under
	 * every product row.
	 *
	 * @param int                 $item_id Order item id (unused).
	 * @param \WC_Order_Item|mixed $item   The order item being rendered.
	 * @param mixed               $product The product (false for shipping; unused).
	 */
	public function render_admin_line_item( $item_id, $item, $product ): void {
		unset( $item_id, $product );
		if ( ! $item instanceof \WC_Order_Item_Shipping ) {
			return;
		}
		$order = $item->get_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$data = self::summary( $order );
		if ( empty( $data ) ) {
			return;
		}
		echo '<div class="fn-order-item-fulfilment" style="margin-top:6px;padding:6px 8px;background:#f6f7f7;border-left:3px solid #2271b1;border-radius:2px;font-size:13px;line-height:1.4">';
		echo '<strong>' . esc_html( $data['method_label'] ) . ':</strong> ';
		echo esc_html( '' !== $data['admin_value'] ? $data['admin_value'] : $data['method_label'] );
		echo '</div>';
	}

	/**
	 * Collection address + directions + opening-times notice on the order
	 * details page (thank-you / My Account). Collection orders only.
	 *
	 * @param WC_Order $order
	 */
	public function render_collection_location_details( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::is_collection( $order ) ) {
			return;
		}
		echo $this->collection_block_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Same block in the customer emails. Skipped on the admin "New order"
	 * email — the team doesn't need directions to their own unit.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param mixed    $email
	 */
	public function render_collection_location_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );
		if ( ! $order instanceof WC_Order || $sent_to_admin || ! self::is_collection( $order ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( $this->collection_block_text() ) . "\n";
			return;
		}
		echo $this->collection_block_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	private static function is_collection( WC_Order $order ): bool {
		$data = self::summary( $order );
		return ! empty( $data ) && 'collection' === $data['type'];
	}

	/**
	 * HTML collection block: business name, linked address (directions), and the
	 * opening-times notice. Inline-styled so it survives email clients.
	 */
	private function collection_block_html(): string {
		$html  = '<div style="margin:16px 0;padding:12px 14px;border:1px solid #e0e0e0;border-radius:6px">';
		$html .= '<p style="margin:0 0 6px;font-weight:700">' . esc_html__( 'Collection', 'fastnutrition-mealprep' ) . '</p>';
		$html .= '<p style="margin:0 0 6px">' . sprintf(
			/* translators: 1: business name (bold), 2: linked address */
			esc_html__( 'Collect your order from %1$s, %2$s.', 'fastnutrition-mealprep' ),
			'<strong>' . esc_html( self::collection_business() ) . '</strong>',
			'<a href="' . esc_url( self::collection_map_url() ) . '" style="color:#2271b1">' . esc_html( self::collection_address() ) . '</a>'
		) . '</p>';
		$html .= '<p style="margin:0;font-size:.9em;color:#666;font-style:italic">' . esc_html( self::collection_notice() ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	/** Plain-text equivalent of collection_block_html() for plain-text emails. */
	private function collection_block_text(): string {
		return sprintf(
			/* translators: 1: business name, 2: address, 3: map URL, 4: opening-times notice */
			__( 'Collection: Collect your order from %1$s, %2$s (%3$s). %4$s', 'fastnutrition-mealprep' ),
			self::collection_business(),
			self::collection_address(),
			self::collection_map_url(),
			self::collection_notice()
		);
	}
}

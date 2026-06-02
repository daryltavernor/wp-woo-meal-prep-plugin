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

		// Customer-facing value: date + time. For collection the pickup location
		// is essential, so append it; for delivery the round/area name is
		// internal jargon and is left for the admin view only.
		$customer_parts = array_filter( [ $date_label, $time_label ] );
		$customer_value = implode( ' · ', $customer_parts );
		if ( $is_collection && '' !== $profile_name ) {
			/* translators: %s: pickup location name */
			$collect = sprintf( __( 'Collect from %s', 'fastnutrition-mealprep' ), $profile_name );
			$customer_value = '' !== $customer_value ? $customer_value . ' · ' . $collect : $collect;
		}

		// Admin value always includes the profile (delivery round / pickup point).
		$admin_parts = array_filter( [ $date_label, $time_label, $profile_name ] );
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
}

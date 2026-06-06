<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\InStore\PrepOrderStatus;
use FastNutrition\MealPrep\Macros\Calculator;
use WC_Order_Item_Product;

final class OrderItemMeta {

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'persist' ], 10, 4 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'rebuild_prep_cache' ], 10, 3 );
		add_action( 'woocommerce_new_order', [ $this, 'rebuild_prep_cache_for_order' ] );
	}

	public function persist( WC_Order_Item_Product $item, string $cart_key, array $values, $order ): void {
		if ( empty( $values[ Selections::CART_KEY ] ) ) {
			return;
		}
		self::write_line_meta( $item, (int) $values['product_id'], (array) $values[ Selections::CART_KEY ] );
	}

	/**
	 * Write the meal-composition meta onto an order line item.
	 *
	 * Extracted from persist() so BOTH the online checkout
	 * (woocommerce_checkout_create_order_line_item) and the offline In-Store
	 * Quick Order builder (InStore\OrderFactory) stamp identical meta —
	 * _fn_selection + _fn_macros_snapshot + the human-readable composition lines
	 * (from Selection::composition_pairs) + Add-ons + Macros — so prep lists,
	 * labels and reporting never fork between the two order sources.
	 *
	 * @param WC_Order_Item_Product $item       The order line item.
	 * @param int                   $product_id The meal product id.
	 * @param array                 $selection  The normalised fn_selection array.
	 */
	public static function write_line_meta( WC_Order_Item_Product $item, int $product_id, array $selection ): void {
		$macros = Calculator::macros_for_selection( $product_id, $selection );

		$item->add_meta_data( '_fn_selection', $selection, true );
		$item->add_meta_data( '_fn_macros_snapshot', $macros, true );

		foreach ( Selection::composition_pairs( $selection ) as $pair ) {
			$item->add_meta_data( $pair['key'], $pair['value'] );
		}
		$addons = Selection::addons_summary( $selection );
		if ( '' !== $addons ) {
			$item->add_meta_data( __( 'Add-ons', 'fastnutrition-mealprep' ), $addons );
		}

		$item->add_meta_data(
			__( 'Macros', 'fastnutrition-mealprep' ),
			sprintf(
				/* translators: 1: kcal, 2: protein, 3: carbs, 4: fat */
				esc_html__( '%1$s kcal · P %2$sg · C %3$sg · F %4$sg', 'fastnutrition-mealprep' ),
				number_format( (float) $macros['kcal'], 0 ),
				number_format( (float) $macros['protein_g'], 1 ),
				number_format( (float) $macros['carbs_g'], 1 ),
				number_format( (float) $macros['fat_g'], 1 )
			)
		);
	}

	public function rebuild_prep_cache_for_order( int $order_id ): void {
		$this->rebuild_prep_cache( $order_id, '', '' );
	}

	public function rebuild_prep_cache( int $order_id, string $from = '', string $to = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fn_prep_cache';

		$fulfilment = $order->get_meta( '_fn_fulfilment' );
		if ( ! is_array( $fulfilment ) || empty( $fulfilment['date'] ) ) {
			return;
		}
		$date = (string) $fulfilment['date'];

		// Re-aggregate all orders for that date in a single pass.
		$wpdb->delete( $table, [ 'fulfilment_date' => $date ], [ '%s' ] );

		$orders = wc_get_orders(
			[
				'status'     => [ 'processing', 'completed', PrepOrderStatus::STATUS ],
				'limit'      => -1,
				'meta_key'   => '_fn_fulfilment',
				'return'     => 'ids',
			]
		);

		$totals = [];
		foreach ( $orders as $oid ) {
			$o  = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			$ff = $o->get_meta( '_fn_fulfilment' );
			if ( ! is_array( $ff ) || ( $ff['date'] ?? '' ) !== $date ) {
				continue;
			}
			foreach ( $o->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$sel = $item->get_meta( '_fn_selection', true );
				if ( ! is_array( $sel ) ) {
					continue;
				}
				$qty = (int) $item->get_quantity();
				foreach ( Selection::ingredient_ids( $sel ) as $ing_id ) {
					$totals[ $ing_id ] = ( $totals[ $ing_id ] ?? 0 ) + $qty;
				}
			}
		}

		foreach ( $totals as $ing_id => $portions ) {
			$wpdb->insert(
				$table,
				[
					'fulfilment_date' => $date,
					'ingredient_id'   => (int) $ing_id,
					'portion_count'   => (int) $portions,
					'updated_at'      => current_time( 'mysql' ),
				],
				[ '%s', '%d', '%d', '%s' ]
			);
		}
	}
}

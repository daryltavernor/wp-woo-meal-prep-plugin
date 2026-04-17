<?php
/**
 * Persists meal selections and macro snapshots onto order items, renders them in admin / emails,
 * and rebuilds the prep cache when order status changes.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Install\Activator;
use FastNutrition\MealPrep\Macros\Calculator;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WC_Order;
use WC_Order_Item_Product;

final class OrderItemMeta {

	public const ORDER_ITEM_SELECTION = '_fn_selection';
	public const ORDER_ITEM_MACROS    = '_fn_macros_snapshot';

	// Statuses that count as "will be fulfilled" for the prep cache.
	private const PREP_STATUSES = [ 'wc-processing', 'wc-on-hold', 'wc-completed' ];

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'persist' ], 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', [ $this, 'pretty_meta_key' ], 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', [ $this, 'pretty_meta_value' ], 10, 3 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'rebuild_prep_cache_for_order' ], 10, 4 );
	}

	public function persist( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		if ( empty( $values[ Selections::CART_KEY ] ) ) {
			return;
		}
		$selection = $values[ Selections::CART_KEY ];
		$item->add_meta_data( self::ORDER_ITEM_SELECTION, $selection, true );

		$macros = Calculator::for_selection( $selection );
		$item->add_meta_data( self::ORDER_ITEM_MACROS, $macros, true );

		// Also expose a friendly, human-readable snapshot so the default email template shows it.
		$lines = [];
		foreach ( $this->human_readable_selection( $selection ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		if ( $macros ) {
			$lines[] = sprintf(
				'Macros: %d kcal · P %.0f · C %.0f · F %.0f',
				(int) ( $macros['kcal'] ?? 0 ),
				(float) ( $macros['protein_g'] ?? 0 ),
				(float) ( $macros['carbs_g'] ?? 0 ),
				(float) ( $macros['fat_g'] ?? 0 )
			);
		}
		if ( $lines ) {
			$item->add_meta_data( __( 'Meal details', 'fastnutrition-mealprep' ), implode( "\n", $lines ), true );
		}
	}

	public function pretty_meta_key( string $display_key, $meta, $item ): string {
		if ( in_array( $display_key, [ self::ORDER_ITEM_SELECTION, self::ORDER_ITEM_MACROS, 'fn_hash' ], true ) ) {
			return ''; // Hide raw keys from the admin/customer display.
		}
		return $display_key;
	}

	public function pretty_meta_value( $display_value, $meta, $item ): string {
		if ( is_object( $meta ) && in_array( $meta->key, [ self::ORDER_ITEM_SELECTION, self::ORDER_ITEM_MACROS, 'fn_hash' ], true ) ) {
			return '';
		}
		return (string) $display_value;
	}

	/**
	 * Rebuild prep-cache totals for the order's fulfilment date whenever order status flips.
	 * Cheaper than recomputing whole day: we invalidate the specific rows and let PrepDashboard refresh on read.
	 */
	public function rebuild_prep_cache_for_order( int $order_id, string $from, string $to, WC_Order $order ): void {
		global $wpdb;
		$fulfilment = $order->get_meta( '_fn_fulfilment' );
		if ( empty( $fulfilment ) || empty( $fulfilment['date'] ) ) {
			return;
		}
		$date   = sanitize_text_field( $fulfilment['date'] );
		$method = sanitize_text_field( $fulfilment['type'] ?? 'delivery' );

		// Clear rows for the date; they'll be rebuilt lazily on next dashboard read.
		$wpdb->delete( Activator::table_prep_cache(), [ 'fulfilment_date' => $date, 'method' => $method ], [ '%s', '%s' ] );
	}

	/**
	 * @return array<string,string>
	 */
	private function human_readable_selection( array $sel ): array {
		$out = [];
		if ( 'set' === ( $sel['mode'] ?? '' ) && ! empty( $sel['set_meal_id'] ) ) {
			$ing = Ingredient::get( (int) $sel['set_meal_id'] );
			if ( $ing ) {
				$out[ __( 'Set Meal', 'fastnutrition-mealprep' ) ] = $ing['title'];
			}
		} else {
			$lookup = static function ( ?int $id ): ?array {
				return $id ? Ingredient::get( $id ) : null;
			};
			$protein = $lookup( isset( $sel['protein_id'] ) ? (int) $sel['protein_id'] : null );
			$carb    = $lookup( isset( $sel['carb_id'] ) ? (int) $sel['carb_id'] : null );
			$greens_titles = [];
			foreach ( (array) ( $sel['greens_ids'] ?? [] ) as $gid ) {
				$ing = $lookup( (int) $gid );
				if ( $ing ) {
					$greens_titles[] = $ing['title'];
				}
			}
			if ( $protein ) {
				$out[ __( 'Protein', 'fastnutrition-mealprep' ) ] = $protein['title'];
			}
			if ( $carb ) {
				$out[ __( 'Carb', 'fastnutrition-mealprep' ) ] = $carb['title'];
			}
			if ( $greens_titles ) {
				$label = count( $greens_titles ) > 1 ? __( 'Greens (x2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' );
				$out[ $label ] = implode( ' + ', $greens_titles );
			}
		}
		if ( ! empty( $sel['addons'] ) ) {
			$out[ __( 'Add-ons', 'fastnutrition-mealprep' ) ] = implode( ', ', array_column( $sel['addons'], 'label' ) );
		}
		return $out;
	}

	/**
	 * Used by the prep dashboard to get aggregated totals for a fulfilment date.
	 *
	 * @return array<string,array<int,array{ingredient_id:int,type:string,title:string,portions:int}>>
	 */
	public static function aggregate_portions( string $date, ?string $method_filter = null ): array {
		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'status'     => [ 'processing', 'on-hold', 'completed' ],
				'meta_query' => [
					[ 'key' => '_fn_fulfilment', 'compare' => 'EXISTS' ],
				],
			]
		);

		$by_type = [
			IngredientType::TERM_PROTEIN  => [],
			IngredientType::TERM_CARB     => [],
			IngredientType::TERM_GREENS   => [],
			IngredientType::TERM_SET_MEAL => [],
		];

		foreach ( $orders as $order ) {
			$fulfilment = $order->get_meta( '_fn_fulfilment' );
			if ( empty( $fulfilment ) || ( $fulfilment['date'] ?? '' ) !== $date ) {
				continue;
			}
			if ( $method_filter && ( $fulfilment['type'] ?? '' ) !== $method_filter ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$selection = $item->get_meta( self::ORDER_ITEM_SELECTION );
				if ( empty( $selection ) || ! is_array( $selection ) ) {
					continue;
				}
				$qty = (int) $item->get_quantity();
				if ( 'set' === $selection['mode'] && ! empty( $selection['set_meal_id'] ) ) {
					self::bump( $by_type[ IngredientType::TERM_SET_MEAL ], (int) $selection['set_meal_id'], $qty );
					continue;
				}
				if ( ! empty( $selection['protein_id'] ) ) {
					self::bump( $by_type[ IngredientType::TERM_PROTEIN ], (int) $selection['protein_id'], $qty );
				}
				if ( ! empty( $selection['carb_id'] ) ) {
					self::bump( $by_type[ IngredientType::TERM_CARB ], (int) $selection['carb_id'], $qty );
				}
				foreach ( (array) ( $selection['greens_ids'] ?? [] ) as $gid ) {
					self::bump( $by_type[ IngredientType::TERM_GREENS ], (int) $gid, $qty );
				}
			}
		}

		// Enrich with title + sort.
		$out = [];
		foreach ( $by_type as $type => $rows ) {
			$enriched = [];
			foreach ( $rows as $id => $portions ) {
				$ing = Ingredient::get( $id );
				$enriched[] = [
					'ingredient_id' => $id,
					'type'          => $type,
					'title'         => $ing['title'] ?? sprintf( '#%d', $id ),
					'portions'      => $portions,
				];
			}
			usort( $enriched, static fn( array $a, array $b ): int => $b['portions'] <=> $a['portions'] );
			$out[ $type ] = $enriched;
		}
		return $out;
	}

	private static function bump( array &$bucket, int $id, int $qty ): void {
		$bucket[ $id ] = ( $bucket[ $id ] ?? 0 ) + $qty;
	}
}

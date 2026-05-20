<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Delivery\BlockedDates;
use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\ProfileResolver;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Macros\Calculator;

final class StoreApiExtensions {

	public const NAMESPACE = 'fastnutrition-mealprep';

	public function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->extend();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ $this, 'extend' ] );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'apply_to_order' ], 10, 2 );
	}

	public function extend(): void {
		if ( ! class_exists( StoreApi::class ) ) {
			return;
		}

		$extend = StoreApi::container()->get( ExtendSchema::class );

		$extend->register_endpoint_data(
			[
				'endpoint'        => 'cart',
				'namespace'       => self::NAMESPACE,
				'data_callback'   => [ $this, 'cart_data' ],
				'schema_callback' => [ $this, 'cart_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);

		$extend->register_endpoint_data(
			[
				'endpoint'        => 'checkout',
				'namespace'       => self::NAMESPACE,
				'data_callback'   => static fn() => [ 'fulfilment' => self::get_session_fulfilment() ],
				'schema_callback' => [ $this, 'checkout_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);

		$extend->register_update_callback(
			[
				'namespace' => self::NAMESPACE,
				'callback'  => [ $this, 'update_callback' ],
			]
		);
	}

	public function cart_data(): array {
		$totals         = Calculator::EMPTY;
		$addon_total    = 0.0;
		$bundle_savings = 0.0;
		$bundle_seen    = [];

		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$selection = $item[ Selections::CART_KEY ] ?? null;
				$qty       = (float) ( $item['quantity'] ?? 0 );

				if ( is_array( $selection ) ) {
					$totals = Calculator::add(
						$totals,
						Calculator::scale(
							Calculator::macros_for_selection( (int) $item['product_id'], $selection ),
							$qty
						)
					);

					foreach ( ( $selection['addons'] ?? [] ) as $addon ) {
						$addon_total += (float) ( $addon['price'] ?? 0 ) * $qty;
					}
				}

				$bundle = $item['fn_bundle'] ?? null;
				if ( is_array( $bundle ) && ! empty( $bundle['applied_tier'] ) ) {
					$product_id = (int) $item['product_id'];
					if ( ! isset( $bundle_seen[ $product_id ] ) ) {
						$bundle_seen[ $product_id ] = true;
						$catalog                    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
						$base                       = $catalog ? (float) $catalog->get_price( 'edit' ) : 0.0;
						$bundled_qty                = (int) ( $bundle['bundle_units'] ?? 0 );
						$bundle_total               = (float) ( $bundle['bundle_total'] ?? 0 );
						$bundle_savings            += max( 0.0, ( $base * $bundled_qty ) - $bundle_total );
					}
				}
			}
		}

		return [
			'macros'         => $totals,
			'addon_total'    => $addon_total,
			'bundle_savings' => $bundle_savings,
		];
	}

	public function cart_schema(): array {
		return [
			'macros'         => [
				'description' => __( 'Running macro totals for the cart.', 'fastnutrition-mealprep' ),
				'type'        => 'object',
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
			'addon_total'    => [
				'description' => __( 'Sum of add-on prices across the cart.', 'fastnutrition-mealprep' ),
				'type'        => 'number',
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
			'bundle_savings' => [
				'description' => __( 'Total saved due to bundle pricing tiers.', 'fastnutrition-mealprep' ),
				'type'        => 'number',
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
		];
	}

	public function checkout_schema(): array {
		return [
			'fulfilment' => [
				'description' => __( 'Selected delivery or collection slot.', 'fastnutrition-mealprep' ),
				'type'        => 'object',
				'context'     => [ 'view', 'edit' ],
			],
		];
	}

	public function update_callback( array $data ): void {
		$action = $data['action'] ?? '';
		if ( 'set_fulfilment' !== $action ) {
			return;
		}
		$payload = $data['fulfilment'] ?? [];
		$type    = in_array( $payload['type'] ?? '', [ 'delivery', 'collection' ], true ) ? (string) $payload['type'] : 'delivery';
		$pid     = isset( $payload['profile_id'] ) ? (int) $payload['profile_id'] : 0;
		$date    = isset( $payload['date'] ) ? sanitize_text_field( (string) $payload['date'] ) : '';
		$slot    = [
			'start' => isset( $payload['slot']['start'] ) ? sanitize_text_field( (string) $payload['slot']['start'] ) : '',
			'end'   => isset( $payload['slot']['end'] ) ? sanitize_text_field( (string) $payload['slot']['end'] ) : '',
		];

		if ( ! $pid || ! $date || ! Profile::get( $pid ) || BlockedDates::is_blocked( $date ) ) {
			WC()->session->set( 'fn_fulfilment', null );
			return;
		}
		WC()->session->set(
			'fn_fulfilment',
			[
				'type'       => $type,
				'profile_id' => $pid,
				'date'       => $date,
				'slot'       => $slot,
			]
		);
	}

	public function apply_to_order( $order, $request ): void {
		$fulfilment = self::get_session_fulfilment();
		if ( ! empty( $fulfilment ) && $order ) {
			$order->update_meta_data( '_fn_fulfilment', $fulfilment );
			$order->save();
		}
	}

	public static function get_session_fulfilment(): array {
		if ( ! WC()->session ) {
			return [];
		}
		$data = WC()->session->get( 'fn_fulfilment' );
		return is_array( $data ) ? $data : [];
	}
}

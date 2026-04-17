<?php
/**
 * Exposes fulfilment slots + selected slot via the WooCommerce Store API extension point,
 * and persists the chosen slot to the order.
 *
 * Namespace used: 'fastnutrition'.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use WC_Order;

final class StoreApiExtensions {

	public const NAMESPACE = 'fastnutrition';

	public function __construct( private readonly SlotAvailability $slots ) {}

	public function register(): void {
		add_action( 'woocommerce_blocks_loaded', [ $this, 'extend' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'save_to_order' ], 10, 2 );
	}

	public function extend(): void {
		if ( ! class_exists( StoreApi::class ) ) {
			return;
		}
		$schema = StoreApi::container()->get( ExtendSchema::class );
		$schema->register_endpoint_data(
			[
				'endpoint'        => 'checkout',
				'namespace'       => self::NAMESPACE,
				'schema_callback' => [ $this, 'schema' ],
				'data_callback'   => [ $this, 'data' ],
			]
		);

		$schema->register_update_callback(
			[
				'namespace'               => self::NAMESPACE,
				'callback'                => [ $this, 'update_from_cart' ],
			]
		);
	}

	public function schema(): array {
		return [
			'fulfilment' => [
				'description' => __( 'Selected delivery or collection profile + date + slot.', 'fastnutrition-mealprep' ),
				'type'        => [ 'object', 'null' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => false,
				'properties'  => [
					'type'       => [ 'type' => 'string' ],
					'profile_id' => [ 'type' => 'integer' ],
					'date'       => [ 'type' => 'string' ],
					'slot'       => [
						'type'       => 'object',
						'properties' => [
							'start' => [ 'type' => 'string' ],
							'end'   => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	public function data(): array {
		$session = WC()->session ? WC()->session->get( 'fn_fulfilment' ) : null;
		return [ 'fulfilment' => is_array( $session ) ? $session : null ];
	}

	/**
	 * Persist the customer's slot selection into the WooCommerce session during checkout.
	 */
	public function update_from_cart( array $data ): void {
		if ( ! WC()->session ) {
			return;
		}
		$fulfilment = $data['fulfilment'] ?? null;
		if ( ! is_array( $fulfilment ) ) {
			WC()->session->set( 'fn_fulfilment', null );
			return;
		}
		$clean = [
			'type'       => in_array( $fulfilment['type'] ?? '', [ 'delivery', 'collection' ], true ) ? $fulfilment['type'] : 'delivery',
			'profile_id' => (int) ( $fulfilment['profile_id'] ?? 0 ),
			'date'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $fulfilment['date'] ?? '' ) ) ? $fulfilment['date'] : '',
			'slot'       => [
				'start' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $fulfilment['slot']['start'] ?? '' ) ) ? $fulfilment['slot']['start'] : '',
				'end'   => preg_match( '/^\d{2}:\d{2}$/', (string) ( $fulfilment['slot']['end'] ?? '' ) ) ? $fulfilment['slot']['end'] : '',
			],
		];
		WC()->session->set( 'fn_fulfilment', $clean );
	}

	public function save_to_order( WC_Order $order, \WP_REST_Request $request ): void {
		$fulfilment = WC()->session ? WC()->session->get( 'fn_fulfilment' ) : null;
		if ( empty( $fulfilment ) || empty( $fulfilment['date'] ) || empty( $fulfilment['slot']['start'] ) ) {
			$order->add_order_note( __( 'Warning: order placed without a selected delivery/collection slot.', 'fastnutrition-mealprep' ) );
			return;
		}
		$order->update_meta_data( '_fn_fulfilment', $fulfilment );
		$order->save();
	}
}

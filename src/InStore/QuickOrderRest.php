<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\BundleMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for the Quick Order admin screen.
 *
 * Routes (namespace fastnutrition/v1, prefix /instore):
 *   GET  /instore/config  — hydrate the screen (product sets + payment).
 *   POST /instore/order   — build a real WooCommerce order via OrderFactory.
 *
 * Both require the `manage_woocommerce` capability (Shop Managers +
 * Administrators) plus the standard logged-in REST cookie nonce. The order is
 * attributed to the logged-in user who took it.
 */
final class QuickOrderRest {

	private const NS = 'fastnutrition/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/instore/config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'config' ],
				'permission_callback' => [ $this, 'require_cap' ],
			]
		);

		register_rest_route(
			self::NS,
			'/instore/order',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_order' ],
				'permission_callback' => [ $this, 'require_cap' ],
			]
		);
	}

	/** Permission gate: Shop Managers + Administrators. */
	public function require_cap(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new WP_Error( 'fn_forbidden', __( 'You do not have permission to take orders.', 'fastnutrition-mealprep' ), [ 'status' => 403 ] );
	}

	public function config(): WP_REST_Response {
		$products  = InStoreSettings::products();
		$overrides = InStoreSettings::overrides();
		$sets      = [];

		foreach ( InStoreSettings::SETS as $set ) {
			$pid = (int) $products[ $set ];
			if ( ! $pid || ! MealProduct::is_meal( $pid ) ) {
				$sets[ $set ] = null;
				continue;
			}
			$product = wc_get_product( $pid );
			$sets[ $set ] = [
				'product_id' => $pid,
				'name'       => $product ? $product->get_name() : '',
				'price'      => $product ? (float) $product->get_price() : 0.0,
				'config'     => MealProduct::get_config( $pid ),
				'addons'     => AddOnMeta::get_addons( $pid ),
				'bundles'    => BundleMeta::get_bundles( $pid ),
				'overrides'  => $overrides[ $set ],
			];
		}

		$payments = [];
		foreach ( InStoreSettings::PAYMENT_METHODS as $slug => $label ) {
			$payments[] = [ 'slug' => $slug, 'label' => $label ];
		}

		return new WP_REST_Response(
			[
				'sets'       => $sets,
				'payments'   => $payments,
				'send_email' => InStoreSettings::send_email_default(),
				'currency'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '£',
			],
			200
		);
	}

	public function create_order( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : [];

		// The logged-in staff member who took the order (attribution).
		$user  = wp_get_current_user();
		$staff = [
			'id'   => (int) $user->ID,
			'name' => (string) ( $user->display_name ?: $user->user_login ),
		];

		$payload = [
			'lines'      => $body['lines'] ?? [],
			'customer'   => $body['customer'] ?? [],
			'fulfilment' => $body['fulfilment'] ?? [],
			'payment'    => (string) ( $body['payment'] ?? '' ),
			'paid'       => ! empty( $body['paid'] ),
			'send_email' => ! empty( $body['send_email'] ),
			'staff'      => $staff,
		];

		$order = OrderFactory::create( $payload );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return new WP_REST_Response(
			[
				'success'      => true,
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'total'        => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) ),
			],
			201
		);
	}
}

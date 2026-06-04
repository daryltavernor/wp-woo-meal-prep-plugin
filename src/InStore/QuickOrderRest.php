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
 * Nonce-free, kiosk-token-protected REST endpoints for the Quick Order screen.
 *
 * Routes (namespace fastnutrition/v1, prefix /instore):
 *   POST /instore/unlock  — exchange the store password for a signed token cookie.
 *   GET  /instore/config  — hydrate the screen (product sets + payment + statuses).
 *   POST /instore/order   — build a real WooCommerce order via OrderFactory.
 *
 * unlock is public but rate-limited; config + order require a valid kiosk token.
 * The order route additionally resolves the staff PIN. None of these use a WP
 * user session, so the iPad is never logged out by WordPress cookie expiry.
 */
final class QuickOrderRest {

	private const NS = 'fastnutrition/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/instore/unlock',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'unlock' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'password' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/instore/config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'config' ],
				'permission_callback' => [ $this, 'require_token' ],
			]
		);

		register_rest_route(
			self::NS,
			'/instore/order',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_order' ],
				'permission_callback' => [ $this, 'require_token' ],
			]
		);
	}

	/** Permission gate: a valid kiosk token cookie must be present. */
	public function require_token(): bool|WP_Error {
		if ( KioskAuth::request_is_authorised() ) {
			return true;
		}
		return new WP_Error( 'fn_locked', __( 'The screen is locked. Enter the store password.', 'fastnutrition-mealprep' ), [ 'status' => 401 ] );
	}

	public function unlock( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		if ( ! KioskAuth::unlock_rate_ok() ) {
			return new WP_Error( 'fn_throttled', __( 'Too many attempts. Wait a few minutes and try again.', 'fastnutrition-mealprep' ), [ 'status' => 429 ] );
		}
		if ( ! InStoreSettings::store_password_is_set() ) {
			return new WP_Error( 'fn_not_configured', __( 'No store password has been set. Configure it under Meal Prep → Quick Order.', 'fastnutrition-mealprep' ), [ 'status' => 503 ] );
		}
		$password = (string) $req->get_param( 'password' );
		if ( ! KioskAuth::verify_password( $password ) ) {
			return new WP_Error( 'fn_bad_password', __( 'Incorrect store password.', 'fastnutrition-mealprep' ), [ 'status' => 401 ] );
		}
		$token = KioskAuth::issue_token();
		KioskAuth::set_cookie( $token );
		return new WP_REST_Response( [ 'unlocked' => true ], 200 );
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
				'sets'         => $sets,
				'payments'     => $payments,
				'send_email'   => InStoreSettings::send_email_default(),
				'currency'     => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£',
			],
			200
		);
	}

	public function create_order( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : [];

		// Resolve the staff PIN → named staff member (attribution gate).
		$staff = StaffPins::resolve( (string) ( $body['pin'] ?? '' ) );
		if ( null === $staff ) {
			return new WP_Error( 'fn_bad_pin', __( 'PIN not recognised.', 'fastnutrition-mealprep' ), [ 'status' => 403 ] );
		}

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

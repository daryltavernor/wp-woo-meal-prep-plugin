<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Labels\LabelPrinter;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\BundleMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Products\StandaloneProduct;
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

		register_rest_route(
			self::NS,
			'/instore/labels',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_labels' ],
				'permission_callback' => [ $this, 'require_cap' ],
			]
		);

		// In-store slot list: same data as the public /slots, but with the
		// relaxed 23:55 cut-off so staff can book tomorrow all evening.
		register_rest_route(
			self::NS,
			'/instore/slots',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'slots' ],
				'permission_callback' => [ $this, 'require_cap' ],
				'args'                => [
					'postcode' => [ 'type' => 'string' ],
					'method'   => [ 'type' => 'string' ],
				],
			]
		);
	}

	public function slots( WP_REST_Request $req ): WP_REST_Response {
		$postcode = (string) $req->get_param( 'postcode' );
		$method   = (string) $req->get_param( 'method' );
		$method   = in_array( $method, [ Profile::METHOD_DELIVERY, Profile::METHOD_COLLECTION ], true ) ? $method : null;

		return new WP_REST_Response(
			[ 'options' => SlotAvailability::options( $postcode, $method, InStoreSettings::INSTORE_CUTOFF ) ],
			200
		);
	}

	/** Permission gate: Shop Managers + Administrators. */
	public function require_cap(): bool|WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new WP_Error( 'fn_forbidden', __( 'You do not have permission to take orders.', 'fastnutrition-mealprep' ), [ 'status' => 403 ] );
	}

	public function config( WP_REST_Request $req ): WP_REST_Response {
		$mode = 'label' === $req->get_param( 'mode' ) ? 'label' : 'order';
		$ids  = 'label' === $mode ? InStoreSettings::label_product_ids() : InStoreSettings::order_product_ids();

		// Tabs are keyed by product id and rendered in the order the settings
		// screen lists them. Each carries enough config for the screen to render
		// the meal builder or the standalone item picker without another request.
		$sets = [];
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( ! MealProduct::is_configurable( $pid ) ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$standalone           = StandaloneProduct::get_config( $pid );
			$sets[ (string) $pid ] = [
				'key'        => (string) $pid,
				'product_id' => $pid,
				'name'       => $product->get_name(),
				'price'      => (float) $product->get_price(),
				'kind'       => $standalone['enabled'] ? 'standalone' : 'meal',
				'config'     => MealProduct::get_config( $pid ),
				'standalone' => $standalone,
				'addons'     => AddOnMeta::get_addons( $pid ),
				'bundles'    => BundleMeta::get_bundles( $pid ),
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

	/** Build the OrderFactory payload from the request body + current user. */
	private function payload_from( WP_REST_Request $req ): array {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : [];

		// The logged-in staff member who took the order (attribution).
		$user = wp_get_current_user();

		return [
			'lines'      => $body['lines'] ?? [],
			'customer'   => $body['customer'] ?? [],
			'fulfilment' => $body['fulfilment'] ?? [],
			'payment'      => (string) ( $body['payment'] ?? '' ),
			'paid'         => ! empty( $body['paid'] ),
			'send_email'   => ! empty( $body['send_email'] ),
			'add_to_prep'  => ! empty( $body['add_to_prep'] ),
			'staff'        => [
				'id'   => (int) $user->ID,
				'name' => (string) ( $user->display_name ?: $user->user_login ),
			],
		];
	}

	public function create_order( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$order = OrderFactory::create( $this->payload_from( $req ) );
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

	/**
	 * Quick Label Maker: build an in-memory order (never saved) and stream a
	 * labels PDF. On success this streams the PDF and exits; on validation
	 * failure it returns a normal REST error.
	 */
	public function create_labels( WP_REST_Request $req ): WP_Error {
		$payload = $this->payload_from( $req );
		// When staff opt in, persist a non-sales "Prep / label only" order so the
		// meals feed the prep sheet; otherwise keep the order purely in memory.
		$order = ! empty( $payload['add_to_prep'] )
			? OrderFactory::create_prep_order( $payload )
			: OrderFactory::assemble_for_labels( $payload );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		// Streams the PDF (inline) and exits.
		LabelPrinter::stream_order_object( $order, LabelPrinter::MODE_FULL );
		// Unreachable, but satisfies the return type if streaming is short-circuited.
		return new WP_Error( 'fn_labels_failed', __( 'Could not generate labels.', 'fastnutrition-mealprep' ), [ 'status' => 500 ] );
	}
}

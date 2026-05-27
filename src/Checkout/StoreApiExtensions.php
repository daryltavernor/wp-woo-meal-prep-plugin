<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Checkout;

use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Cart\Surcharge;
use FastNutrition\MealPrep\Cart\TotalsDisplay;
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
		// Narrow the available shipping rates to match the customer's chosen
		// fulfilment type. Priority 100 so it runs after third-party shipping
		// plugins have populated their rates.
		add_filter( 'woocommerce_package_rates', [ $this, 'filter_package_rates' ], 100, 2 );
		// Suppress shipping calculation entirely until the customer has picked
		// a slot (= until fn_fulfilment is in session). Without this, the
		// Blocks cart fetches its data via the Store API and computes a
		// default shipping rate (the first WC rate for the zone) which gets
		// added to the displayed total before the customer has chosen
		// anything. TotalsDisplay::maybe_hide_cart_shipping only fires on the
		// `wp` action so it never runs against the Store API endpoint.
		add_filter( 'woocommerce_cart_ready_to_calc_shipping', [ $this, 'gate_shipping_calc' ], 100 );
		// On every render of the basket page, clear any leftover fulfilment +
		// chosen-shipping-method from the session. WC sessions persist across
		// browsing sessions for logged-in users, so without this, a customer
		// who previously picked Delivery in a prior visit would see the £fee
		// delivery rate calculated into the basket total even though they
		// haven't picked anything for THIS order yet. The customer re-picks
		// their slot in step 2 of checkout.
		add_action( 'wp', [ $this, 'clear_stale_fulfilment_on_cart_page' ] );
		// Same safety for cart mutations: adding or removing an item
		// invalidates any previously-picked slot so the customer gets a
		// fresh selection at checkout.
		add_action( 'woocommerce_add_to_cart', [ $this, 'clear_session_fulfilment' ] );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'clear_session_fulfilment' ] );
		add_action( 'woocommerce_cart_emptied', [ $this, 'clear_session_fulfilment' ] );
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
		$totals = Calculator::EMPTY;

		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$selection = $item[ Selections::CART_KEY ] ?? null;
				if ( ! is_array( $selection ) ) {
					continue;
				}
				$totals = Calculator::add(
					$totals,
					Calculator::scale(
						Calculator::macros_for_selection( (int) $item['product_id'], $selection ),
						(float) $item['quantity']
					)
				);
			}
		}

		$summary   = TotalsDisplay::compute_summary();
		$upsells   = TotalsDisplay::compute_upsells();
		$surcharge = Surcharge::status();

		return [
			'macros'         => $totals,
			'addon_total'    => $summary['addon_total'],
			'bundle_savings' => $summary['bundle_savings'],
			'upsells'        => $upsells,
			'surcharge'      => $surcharge,
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
			'upsells'        => [
				'description' => __( 'Per-product hints showing how many more meals to add to reach the next bundle tier.', 'fastnutrition-mealprep' ),
				'type'        => 'array',
				'context'     => [ 'view' ],
				'readonly'    => true,
			],
			'surcharge'      => [
				'description' => __( 'Status of the basket surcharge: enabled, threshold, amount, and whether it currently applies.', 'fastnutrition-mealprep' ),
				'type'        => 'object',
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

		if (
			! $pid
			|| ! $date
			|| ! Profile::get( $pid )
			|| BlockedDates::is_blocked( $date )
			|| $date < SlotAvailability::earliest_allowed_date()
		) {
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

		// Auto-select the matching WC shipping method so the order carries a
		// correct shipping line. We hide WC's native picker on the checkout
		// because our slot picker fully replaces it.
		$this->apply_shipping_method_for_type( $type );
	}

	private function apply_shipping_method_for_type( string $type ): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session || ! WC()->shipping() ) {
			return;
		}
		// Recompute packages so filter_package_rates() runs against the
		// just-saved fulfilment session value before we look up the surviving
		// rate id.
		if ( WC()->cart ) {
			WC()->cart->calculate_shipping();
		}
		$preferred = $this->pick_shipping_rate_id( $type );
		if ( null === $preferred ) {
			return;
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		if ( ! $chosen ) {
			$chosen = [];
		}
		$chosen[0] = $preferred;
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		// calculate_totals() re-runs calculate_shipping() internally, so we
		// don't need a second explicit call here.
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * After filter_package_rates() has narrowed the rate list to those that
	 * match the customer's chosen fulfilment type, pick the first remaining
	 * rate id from the first package. Returns null if no rate survives.
	 */
	private function pick_shipping_rate_id( string $type ): ?string {
		unset( $type ); // filter has already narrowed by type; kept for callers.
		$packages = WC()->shipping()->get_packages();
		foreach ( $packages as $package ) {
			foreach ( ( $package['rates'] ?? [] ) as $rate ) {
				return $rate->get_id();
			}
		}
		return null;
	}

	/**
	 * Wipe fn_fulfilment + chosen_shipping_methods from the WC session on
	 * cart page render. Runs on the `wp` action where is_cart() is reliable;
	 * the subsequent Store API fetch the Blocks cart issues then sees an
	 * empty fulfilment, filter_package_rates returns [], and shipping_total
	 * stays at 0 on the basket page even for logged-in customers whose
	 * session carries a previously-picked slot.
	 */
	public function clear_stale_fulfilment_on_cart_page(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() || is_checkout() ) {
			return;
		}
		$this->clear_session_fulfilment();
	}

	/**
	 * Clear the fulfilment + chosen-shipping-method from the WC session.
	 * Idempotent — safe to call on any cart mutation event.
	 */
	public function clear_session_fulfilment(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}
		WC()->session->set( 'fn_fulfilment', null );
		WC()->session->set( 'chosen_shipping_methods', [] );
	}

	/**
	 * Short-circuit WC's shipping calculation until the customer has chosen a
	 * fulfilment slot. Returning false here makes WC_Cart::show_shipping()
	 * return false → calculate_shipping() skips → shipping_total stays 0.
	 *
	 * Applies on every cart load, including the Store API cart endpoint
	 * (which is how the Blocks basket / checkout fetches its totals).
	 */
	public function gate_shipping_calc( bool $ready ): bool {
		$fulfilment = self::get_session_fulfilment();
		if ( empty( $fulfilment['type'] ) ) {
			return false;
		}
		return $ready;
	}

	/**
	 * Narrow the package's rate list to match the customer's chosen
	 * fulfilment type. With type=collection the Delivery rate is removed; with
	 * type=delivery the Collection rate is removed. With no fulfilment set
	 * (basket page, or step 1 of checkout before the slot picker has run) we
	 * return an empty rate list — this prevents WC from picking a default
	 * rate (the first one in the zone, typically the £fee Delivery) and
	 * adding its cost to the displayed total before the customer has chosen
	 * anything.
	 *
	 * gate_shipping_calc() also returns false in this case, which suppresses
	 * the front-end shipping table display in the legacy shortcode cart, but
	 * it does not stop WC_Cart::calculate_shipping() from running — only this
	 * empty-rate return guarantees shipping_total stays 0 across all UI paths
	 * including the Blocks Store API cart endpoint.
	 *
	 * This is also the single guarantee that customers cannot be charged a
	 * delivery fee when they picked Collection.
	 *
	 * @param array<string, \WC_Shipping_Rate> $rates
	 * @param array                            $package
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public function filter_package_rates( array $rates, array $package ): array {
		unset( $package );
		$fulfilment = self::get_session_fulfilment();
		$type       = $fulfilment['type'] ?? '';
		if ( 'delivery' !== $type && 'collection' !== $type ) {
			return [];
		}

		$want_pickup = ( 'collection' === $type );
		$filtered    = [];
		foreach ( $rates as $key => $rate ) {
			if ( self::is_pickup_like( $rate ) === $want_pickup ) {
				$filtered[ $key ] = $rate;
			}
		}
		return $filtered;
	}

	/**
	 * Identify pickup-like rates by either WC's pickup method IDs OR by label
	 * text — this shop's zones use a plain `flat_rate` titled "Collection"
	 * rather than the dedicated `local_pickup` method, so matching on method
	 * id alone is not enough.
	 */
	private static function is_pickup_like( \WC_Shipping_Rate $rate ): bool {
		if ( in_array( $rate->get_method_id(), [ 'local_pickup', 'pickup_location' ], true ) ) {
			return true;
		}
		$label = strtolower( (string) $rate->get_label() );
		return false !== strpos( $label, 'collection' )
			|| false !== strpos( $label, 'pickup' )
			|| false !== strpos( $label, 'pick up' )
			|| false !== strpos( $label, 'pick-up' );
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

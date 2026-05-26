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
		if ( WC()->cart ) {
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
		}
	}

	private function pick_shipping_rate_id( string $type ): ?string {
		$packages = WC()->shipping()->get_packages();
		if ( empty( $packages ) ) {
			return null;
		}

		// Collect every rate across every package so we can score them
		// holistically. Most shops have one package.
		$rates = [];
		foreach ( $packages as $package ) {
			foreach ( ( $package['rates'] ?? [] ) as $rate ) {
				$rates[] = $rate;
			}
		}
		if ( empty( $rates ) ) {
			return null;
		}

		// Identify pickup-like rates by either WC's pickup method IDs OR by
		// label text — the user's zones use a plain `flat_rate` titled
		// "Collection" rather than the dedicated `local_pickup` method, so
		// matching on method id alone is not enough.
		$pickup_method_ids = [ 'local_pickup', 'pickup_location' ];
		$is_pickup_like    = static function ( $rate ) use ( $pickup_method_ids ): bool {
			if ( in_array( $rate->get_method_id(), $pickup_method_ids, true ) ) {
				return true;
			}
			$label = strtolower( (string) $rate->get_label() );
			return false !== strpos( $label, 'collection' )
				|| false !== strpos( $label, 'pickup' )
				|| false !== strpos( $label, 'pick up' )
				|| false !== strpos( $label, 'pick-up' );
		};

		if ( 'collection' === $type ) {
			// Collection: prefer a pickup-like rate. If none, fall back to
			// the cheapest available rate (collection is typically free).
			foreach ( $rates as $rate ) {
				if ( $is_pickup_like( $rate ) ) {
					return $rate->get_id();
				}
			}
			usort( $rates, static fn( $a, $b ) => (float) $a->get_cost() <=> (float) $b->get_cost() );
			return $rates[0]->get_id();
		}

		// Delivery: exclude pickup-like rates. Among what's left prefer a
		// rate whose label mentions "delivery"; otherwise pick the most
		// expensive (delivery rates usually carry the highest cost in the
		// zone). Last resort: any non-pickup rate.
		$delivery_candidates = array_values( array_filter( $rates, static fn( $r ) => ! $is_pickup_like( $r ) ) );
		if ( empty( $delivery_candidates ) ) {
			return $rates[0]->get_id();
		}
		foreach ( $delivery_candidates as $rate ) {
			if ( false !== strpos( strtolower( (string) $rate->get_label() ), 'delivery' ) ) {
				return $rate->get_id();
			}
		}
		usort( $delivery_candidates, static fn( $a, $b ) => (float) $b->get_cost() <=> (float) $a->get_cost() );
		return $delivery_candidates[0]->get_id();
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

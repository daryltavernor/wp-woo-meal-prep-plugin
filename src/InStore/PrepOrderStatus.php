<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * A dedicated "Prep / label only" WooCommerce order status.
 *
 * The Quick Label Maker can optionally drop a real order in this status so the
 * meals feed the kitchen prep sheet/dashboard and consume slot capacity — WITHOUT
 * ever counting as a sale. The status is deliberately kept out of WooCommerce's
 * "paid" and reporting status sets, so revenue, analytics and the legacy reports
 * never include it. Stock is not reduced (a custom status triggers no stock
 * change), and no transactional emails are sent.
 */
final class PrepOrderStatus {

	/** Unprefixed status, used with set_status() and wc_get_orders( status ). */
	public const STATUS = 'fn-prep';

	/** Prefixed WordPress post-status slug. */
	public const SLUG = 'wc-fn-prep';

	public function register(): void {
		add_action( 'init', [ $this, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_dropdown' ] );
		// Belt-and-braces: keep it out of the legacy reports status set.
		add_filter( 'woocommerce_reports_order_statuses', [ $this, 'exclude_from_reports' ] );
	}

	public function register_status(): void {
		// Mirrors how WooCommerce core registers its own order statuses, so
		// WC_Order::set_status() accepts it and orders persist in this status.
		register_post_status(
			self::SLUG,
			[
				'label'                     => _x( 'Prep / label only', 'Order status', 'fastnutrition-mealprep' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count */
				'label_count'               => _n_noop(
					'Prep / label only <span class="count">(%s)</span>',
					'Prep / label only <span class="count">(%s)</span>',
					'fastnutrition-mealprep'
				),
			]
		);
	}

	/**
	 * Add the status to the order-status dropdown, just after "On hold".
	 *
	 * @param array<string,string> $statuses
	 * @return array<string,string>
	 */
	public function add_to_dropdown( array $statuses ): array {
		$out = [];
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-on-hold' === $key ) {
				$out[ self::SLUG ] = _x( 'Prep / label only', 'Order status', 'fastnutrition-mealprep' );
			}
		}
		if ( ! isset( $out[ self::SLUG ] ) ) {
			$out[ self::SLUG ] = _x( 'Prep / label only', 'Order status', 'fastnutrition-mealprep' );
		}
		return $out;
	}

	/**
	 * @param string[] $statuses
	 * @return string[]
	 */
	public function exclude_from_reports( array $statuses ): array {
		return array_values( array_diff( $statuses, [ self::STATUS, self::SLUG ] ) );
	}

	/**
	 * Order statuses the kitchen prep tools treat as "active" (on the prep sheet,
	 * dashboard, label printing and slot-capacity counting). Includes the
	 * prep-only status so label-maker contributions show up.
	 *
	 * @return string[]
	 */
	public static function active_statuses(): array {
		return [ 'processing', 'completed', 'on-hold', self::STATUS ];
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

use WC_Order;

/**
 * Distinguishes in-store (offline) orders in the WooCommerce Orders list.
 *
 * Online orders are left exactly as they are — no marker, no reclassification.
 * Offline orders (created_via = fn_instore / _fn_offline_order = yes) get an
 * "In-store" badge in a new Source column, and a toolbar dropdown lets staff
 * filter the list to just in-store (or just online) orders.
 *
 * Works on both the HPOS orders screen and the legacy post-based screen.
 */
final class OrdersListColumn {

	private const FILTER_VAR = 'fn_source';
	private const COLUMN_ID  = 'fn_source';

	public function register(): void {
		// HPOS orders screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ] );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_column_hpos' ], 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_filter' ], 10, 1 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ $this, 'filter_query_hpos' ] );

		// Legacy post-based orders screen.
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column_legacy' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'render_filter_legacy' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_query_legacy' ] );
	}

	/** Is this an in-store / offline order? */
	public static function is_offline( WC_Order $order ): bool {
		return 'fn_instore' === $order->get_created_via() || 'yes' === $order->get_meta( '_fn_offline_order' );
	}

	/**
	 * Insert a "Source" column after the order status / number column.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key || 'order_number' === $key ) {
				$out[ self::COLUMN_ID ] = __( 'Source', 'fastnutrition-mealprep' );
			}
		}
		if ( ! isset( $out[ self::COLUMN_ID ] ) ) {
			$out[ self::COLUMN_ID ] = __( 'Source', 'fastnutrition-mealprep' );
		}
		return $out;
	}

	public function render_column_hpos( string $column, WC_Order $order ): void {
		if ( self::COLUMN_ID === $column ) {
			$this->badge( $order );
		}
	}

	public function render_column_legacy( string $column, int $post_id ): void {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->badge( $order );
		}
	}

	/** Is this a prep / label-only order (from the Quick Label Maker)? */
	public static function is_prep_only( WC_Order $order ): bool {
		// Either signal is sufficient — they're set together, but checking both
		// keeps the badge correct even if one didn't take.
		return 'yes' === $order->get_meta( '_fn_prep_only' ) || PrepOrderStatus::STATUS === $order->get_status();
	}

	/** Print the in-store badge (online orders render nothing). */
	private function badge( WC_Order $order ): void {
		if ( ! self::is_offline( $order ) && ! self::is_prep_only( $order ) ) {
			return;
		}
		$staff = (string) $order->get_meta( '_fn_staff_name' );

		// Prep / label-only orders (from the Quick Label Maker) get their own
		// amber badge so staff don't mistake them for sellable in-store orders.
		if ( self::is_prep_only( $order ) ) {
			printf(
				'<span title="%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#dba617;color:#fff;font-weight:600;font-size:11px;line-height:1.6">%2$s</span>',
				esc_attr( '' !== $staff ? sprintf( /* translators: %s: staff member */ __( 'Prep / label only — by %s', 'fastnutrition-mealprep' ), $staff ) : __( 'Prep / label only (no sale)', 'fastnutrition-mealprep' ) ),
				esc_html__( 'Label/Prep', 'fastnutrition-mealprep' )
			);
			return;
		}

		printf(
			'<span title="%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#c6f432;color:#16210a;font-weight:600;font-size:11px;line-height:1.6">%2$s</span>',
			esc_attr( '' !== $staff ? sprintf( /* translators: %s: staff member */ __( 'Taken by %s', 'fastnutrition-mealprep' ), $staff ) : __( 'In-store order', 'fastnutrition-mealprep' ) ),
			esc_html__( 'In-store', 'fastnutrition-mealprep' )
		);
	}

	// --- Filter dropdown ----------------------------------------------------

	private function current_source(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table read filter, no state change.
		$val = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';
		return in_array( $val, [ 'instore', 'online' ], true ) ? $val : '';
	}

	private function dropdown_html(): string {
		$current = $this->current_source();
		$options = [
			''        => __( 'All sources', 'fastnutrition-mealprep' ),
			'instore' => __( 'In-store orders', 'fastnutrition-mealprep' ),
			'online'  => __( 'Online orders', 'fastnutrition-mealprep' ),
		];
		$html = '<select name="' . esc_attr( self::FILTER_VAR ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';
		return $html;
	}

	/** HPOS toolbar dropdown. */
	public function render_filter( $order_type ): void {
		if ( 'shop_order' !== $order_type ) {
			return;
		}
		echo $this->dropdown_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/** Legacy toolbar dropdown. */
	public function render_filter_legacy(): void {
		global $typenow;
		if ( 'shop_order' !== $typenow ) {
			return;
		}
		echo $this->dropdown_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Apply the source filter to the HPOS orders query.
	 *
	 * @param array $args
	 * @return array
	 */
	public function filter_query_hpos( array $args ): array {
		$source = $this->current_source();
		if ( '' === $source ) {
			return $args;
		}
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}
		$args['meta_query'][] = ( 'instore' === $source )
			? [ 'key' => '_fn_offline_order', 'value' => 'yes' ]
			: [ 'key' => '_fn_offline_order', 'compare' => 'NOT EXISTS' ];
		return $args;
	}

	/** Apply the source filter to the legacy post-based orders query. */
	public function filter_query_legacy( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== ( $query->get( 'post_type' ) ?: '' ) ) {
			return;
		}
		$source = $this->current_source();
		if ( '' === $source ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = ( 'instore' === $source )
			? [ 'key' => '_fn_offline_order', 'value' => 'yes' ]
			: [ 'key' => '_fn_offline_order', 'compare' => 'NOT EXISTS' ];
		$query->set( 'meta_query', $meta_query );
	}
}

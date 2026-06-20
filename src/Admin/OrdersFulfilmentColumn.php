<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use WC_Order;

/**
 * Surfaces fulfilment info on the WooCommerce Orders list:
 *   - a concise "Fulfilment" column (date · method · slot), and
 *   - a toolbar dropdown to filter to orders due on a chosen date within the
 *     next 10 days.
 *
 * The fulfilment date lives inside serialized `_fn_fulfilment` meta, so the
 * filter matches the exact serialized fragment (s:4:"date";s:10:"Y-m-d") via a
 * LIKE — reliable and needs no extra stored field. Works on the HPOS and the
 * legacy orders screens.
 */
final class OrdersFulfilmentColumn {

	private const FILTER_VAR = 'fn_ff_date';
	private const COLUMN_ID  = 'fn_fulfilment';
	private const DAYS_AHEAD = 10;

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

	/**
	 * Add a "Fulfilment" column, ideally just after the order date column.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_date' === $key || 'order_number' === $key ) {
				$out[ self::COLUMN_ID ] = __( 'Fulfilment', 'fastnutrition-mealprep' );
			}
		}
		if ( ! isset( $out[ self::COLUMN_ID ] ) ) {
			$out[ self::COLUMN_ID ] = __( 'Fulfilment', 'fastnutrition-mealprep' );
		}
		return $out;
	}

	public function render_column_hpos( string $column, WC_Order $order ): void {
		if ( self::COLUMN_ID === $column ) {
			$this->cell( $order );
		}
	}

	public function render_column_legacy( string $column, int $post_id ): void {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->cell( $order );
		}
	}

	/** Concise "date · method · slot", kept narrow so it doesn't stretch the table. */
	private function cell( WC_Order $order ): void {
		$ff = $order->get_meta( '_fn_fulfilment' );
		if ( ! is_array( $ff ) || empty( $ff['date'] ) ) {
			echo '<span style="color:#a7aaad;">—</span>';
			return;
		}
		$ts    = strtotime( (string) $ff['date'] );
		$date  = $ts ? date_i18n( 'D j M', $ts ) : (string) $ff['date'];
		$type  = (string) ( $ff['type'] ?? '' );
		$label = 'delivery' === $type ? __( 'Delivery', 'fastnutrition-mealprep' ) : ( 'collection' === $type ? __( 'Collection', 'fastnutrition-mealprep' ) : '' );

		$slot      = is_array( $ff['slot'] ?? null ) ? $ff['slot'] : [];
		$start     = (string) ( $slot['start'] ?? '' );
		$end       = (string) ( $slot['end'] ?? '' );
		$slot_text = ( '' !== $start || '' !== $end ) ? trim( $start . '–' . $end, '–' ) : '';

		$line2 = trim( $label . ( '' !== $label && '' !== $slot_text ? ' · ' : '' ) . $slot_text );

		echo '<strong>' . esc_html( $date ) . '</strong>';
		if ( '' !== $line2 ) {
			echo '<br><small style="color:#50575e;">' . esc_html( $line2 ) . '</small>';
		}
	}

	// --- Filter dropdown ----------------------------------------------------

	private function current_date(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table read filter, no state change.
		$val = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ? $val : '';
	}

	private function dropdown_html(): string {
		$current = $this->current_date();
		$tz      = wp_timezone();
		$today   = new \DateTimeImmutable( 'now', $tz );

		$html = '<select name="' . esc_attr( self::FILTER_VAR ) . '">';
		$html .= '<option value="">' . esc_html__( 'Any fulfilment date', 'fastnutrition-mealprep' ) . '</option>';
		for ( $i = 0; $i < self::DAYS_AHEAD; $i++ ) {
			$d     = $today->modify( "+{$i} days" );
			$value = $d->format( 'Y-m-d' );
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $d->format( 'D j M' ) )
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

	/** The serialized fragment that uniquely identifies a fulfilment date. */
	private static function date_fragment( string $date ): string {
		return 's:4:"date";s:10:"' . $date . '"';
	}

	/**
	 * Apply the date filter to the HPOS orders query.
	 *
	 * @param array $args
	 * @return array
	 */
	public function filter_query_hpos( array $args ): array {
		$date = $this->current_date();
		if ( '' === $date ) {
			return $args;
		}
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}
		$args['meta_query'][] = [
			'key'     => '_fn_fulfilment',
			'value'   => self::date_fragment( $date ),
			'compare' => 'LIKE',
		];
		return $args;
	}

	/** Apply the date filter to the legacy post-based orders query. */
	public function filter_query_legacy( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== ( $query->get( 'post_type' ) ?: '' ) ) {
			return;
		}
		$date = $this->current_date();
		if ( '' === $date ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = [
			'key'     => '_fn_fulfilment',
			'value'   => self::date_fragment( $date ),
			'compare' => 'LIKE',
		];
		$query->set( 'meta_query', $meta_query );
	}
}

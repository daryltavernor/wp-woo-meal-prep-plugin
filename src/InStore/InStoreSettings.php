<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Products\StandaloneProduct;

/**
 * Settings + option storage for the Quick Order tools.
 *
 * Rather than a fixed set of product slots, the Quick Order screen and the
 * Quick Label Maker each draw from a checklist of ELIGIBLE products — any
 * published product that has the Meal Builder or a Standalone Product enabled.
 * The admin ticks which of those appear in the order screen and which appear in
 * the label maker (the two lists are independent), so adding a new sellable
 * product is just a matter of enabling it on the product and ticking it here.
 *
 * Access to the screens is governed by the WordPress login + `manage_woocommerce`
 * capability (Shop Managers + Administrators). Payment methods and the
 * paid/unpaid → status mapping are fixed product decisions and live here as
 * constants.
 */
final class InStoreSettings {

	public const OPTION_ORDER_PRODUCTS = 'fn_instore_order_products';
	public const OPTION_LABEL_PRODUCTS = 'fn_instore_label_products';
	public const OPTION_SEND_EMAIL     = 'fn_instore_send_email_default';

	public const PAGE_SLUG = 'fn-quick-order-settings';

	/** Fixed payment methods (slug => label). */
	public const PAYMENT_METHODS = [
		'cash'          => 'Cash',
		'card_terminal' => 'Card terminal',
		'bacs'          => 'Bank transfer',
		'account'       => 'Account / on credit',
	];

	/** Decided mapping: paid orders complete, unpaid orders sit on hold. */
	public const STATUS_PAID   = 'completed';
	public const STATUS_UNPAID = 'on-hold';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Option accessors ---------------------------------------------------

	/**
	 * Every product that can be sold through the Quick Order tools: published
	 * products with the Meal Builder OR a Standalone Product enabled. Keyed by
	 * product id, in title order.
	 *
	 * @return array<int,array{id:int,name:string,kind:string}>
	 */
	public static function eligible_products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$ids = wc_get_products(
			[
				'status'     => 'publish',
				'limit'      => -1,
				'return'     => 'ids',
				'orderby'    => 'title',
				'order'      => 'ASC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[ 'key' => '_fn_is_meal', 'value' => '1' ],
					[ 'key' => StandaloneProduct::META_ENABLED, 'value' => '1' ],
				],
			]
		);
		$out = [];
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			// Standalone takes precedence when a product somehow has both enabled,
			// matching how the product page renders it.
			$out[ $pid ] = [
				'id'   => $pid,
				'name' => (string) get_the_title( $pid ),
				'kind' => StandaloneProduct::is_enabled( $pid ) ? 'standalone' : 'meal',
			];
		}
		return $out;
	}

	/** Product ids enabled for the Quick Order screen. */
	public static function order_product_ids(): array {
		return self::resolve_ids( self::OPTION_ORDER_PRODUCTS );
	}

	/** Product ids enabled for the Quick Label Maker. */
	public static function label_product_ids(): array {
		return self::resolve_ids( self::OPTION_LABEL_PRODUCTS );
	}

	/**
	 * Resolve a saved id list against the current eligible set. When the option
	 * has never been saved, default to EVERY eligible product so a fresh install
	 * works without configuration; once saved (even empty) the choice is honoured.
	 *
	 * @return int[]
	 */
	private static function resolve_ids( string $option ): array {
		$eligible = array_keys( self::eligible_products() );
		$saved    = get_option( $option, null );
		if ( null === $saved || ! is_array( $saved ) ) {
			return $eligible;
		}
		$saved = array_map( 'intval', $saved );
		// Preserve the eligible (title) ordering.
		return array_values( array_intersect( $eligible, $saved ) );
	}

	public static function send_email_default(): bool {
		return '1' === (string) get_option( self::OPTION_SEND_EMAIL, '0' );
	}

	public static function payment_label( string $slug ): string {
		return self::PAYMENT_METHODS[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
	}

	// --- Settings screen ----------------------------------------------------

	public function handle_actions(): void {
		if ( ! isset( $_POST['fn_instore_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_instore_nonce'] ) ), 'fn_instore_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['fn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_action'] ) ) : '';

		if ( 'save_products' === $action ) {
			$order = isset( $_POST['fn_order_products'] ) && is_array( $_POST['fn_order_products'] )
				? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['fn_order_products'] ) ) ) )
				: [];
			$label = isset( $_POST['fn_label_products'] ) && is_array( $_POST['fn_label_products'] )
				? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['fn_label_products'] ) ) ) )
				: [];
			update_option( self::OPTION_ORDER_PRODUCTS, $order, false );
			update_option( self::OPTION_LABEL_PRODUCTS, $label, false );
			update_option( self::OPTION_SEND_EMAIL, ! empty( $_POST['fn_send_email_default'] ) ? '1' : '0', false );
			set_transient( 'fn_instore_notice', __( 'Quick Order settings saved.', 'fastnutrition-mealprep' ), 30 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$notice = get_transient( 'fn_instore_notice' );
		if ( $notice ) {
			delete_transient( 'fn_instore_notice' );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}

		$eligible   = self::eligible_products();
		$order_ids  = self::order_product_ids();
		$label_ids  = self::label_product_ids();
		$screen_url = admin_url( 'admin.php?page=' . QuickOrderPage::ADMIN_SLUG );

		echo '<div class="wrap"><h1>' . esc_html__( 'Quick Order — Settings', 'fastnutrition-mealprep' ) . '</h1>';

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:820px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'Staff order screen', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'A fast, touch-first screen for staff to take phone and walk-in orders straight into WooCommerce. It lives in the WordPress admin and is available to Shop Managers and Administrators — orders are attributed to whoever is signed in.', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:8px 0 0"><a class="button button-primary" href="' . esc_url( $screen_url ) . '">' . esc_html__( 'Open Quick Order screen', 'fastnutrition-mealprep' ) . '</a></p>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Products', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Tick which products appear as tabs in the Quick Order screen and in the Quick Label Maker. Only products with the Meal Builder or a Standalone Product enabled are listed here.', 'fastnutrition-mealprep' ) . '</p>';

		if ( empty( $eligible ) ) {
			echo '<p><em>' . esc_html__( 'No eligible products yet. Enable the Meal Builder or a Standalone Product on a product to make it available here.', 'fastnutrition-mealprep' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'fn_instore_settings', 'fn_instore_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_products" />';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="text-align:center">' . esc_html__( 'Quick Order', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="text-align:center">' . esc_html__( 'Quick Label Maker', 'fastnutrition-mealprep' ) . '</th>';
		echo '</tr></thead><tbody>';
		$type_labels = [
			'meal'       => __( 'Meal Builder', 'fastnutrition-mealprep' ),
			'standalone' => __( 'Standalone', 'fastnutrition-mealprep' ),
		];
		foreach ( $eligible as $pid => $row ) {
			printf(
				'<tr><td><strong>%1$s</strong></td><td>%2$s</td><td style="text-align:center"><input type="checkbox" name="fn_order_products[]" value="%3$d" %4$s /></td><td style="text-align:center"><input type="checkbox" name="fn_label_products[]" value="%3$d" %5$s /></td></tr>',
				esc_html( $row['name'] ),
				esc_html( $type_labels[ $row['kind'] ] ?? $row['kind'] ),
				(int) $pid,
				checked( in_array( (int) $pid, $order_ids, true ), true, false ),
				checked( in_array( (int) $pid, $label_ids, true ), true, false )
			);
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:1.5em">' . esc_html__( 'Confirmation email', 'fastnutrition-mealprep' ) . '</h2>';
		printf(
			'<p><label><input type="checkbox" name="fn_send_email_default" value="1" %s /> %s</label></p>',
			checked( self::send_email_default(), true, false ),
			esc_html__( 'Default the "send confirmation email" toggle on (only sends when an email address is entered).', 'fastnutrition-mealprep' )
		);

		submit_button( __( 'Save settings', 'fastnutrition-mealprep' ) );
		echo '</form>';

		echo '</div>';
	}
}

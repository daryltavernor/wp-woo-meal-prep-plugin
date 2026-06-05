<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * Settings + option storage for the Quick Order tool.
 *
 * Owns the three product-set ids (standard / bulk / sweets), the optional
 * per-set ingredient override allow-lists for in-store offers, and the default
 * "send confirmation email" toggle.
 *
 * Access to the Quick Order screen is governed by the WordPress login +
 * `manage_woocommerce` capability (Shop Managers + Administrators); there is no
 * separate store password or PIN. Payment methods and the paid/unpaid → status
 * mapping are fixed product decisions and live here as constants.
 */
final class InStoreSettings {

	public const OPTION_PRODUCTS   = 'fn_instore_products';
	public const OPTION_OVERRIDES  = 'fn_instore_overrides';
	public const OPTION_SEND_EMAIL = 'fn_instore_send_email_default';

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

	/** The three logical product sets the screen offers. */
	public const SETS = [ 'standard', 'bulk', 'sweets' ];

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Option accessors ---------------------------------------------------

	/**
	 * @return array{standard:int,bulk:int,sweets:int}
	 */
	public static function products(): array {
		$raw = (array) get_option( self::OPTION_PRODUCTS, [] );
		$out = [];
		foreach ( self::SETS as $set ) {
			$out[ $set ] = isset( $raw[ $set ] ) ? (int) $raw[ $set ] : self::auto_detect_product( $set );
		}
		return $out;
	}

	/**
	 * Optional per-set ingredient override allow-lists for in-store offers.
	 * Blank for a type = inherit the live product's allowed list.
	 *
	 * @return array<string,array<string,int[]>>
	 */
	public static function overrides(): array {
		$raw = (array) get_option( self::OPTION_OVERRIDES, [] );
		$out = [];
		foreach ( self::SETS as $set ) {
			$out[ $set ] = [];
			foreach ( [ 'proteins', 'carbs', 'greens', 'set_meals', 'sweets' ] as $type ) {
				$out[ $set ][ $type ] = isset( $raw[ $set ][ $type ] ) && is_array( $raw[ $set ][ $type ] )
					? array_values( array_filter( array_map( 'intval', $raw[ $set ][ $type ] ) ) )
					: [];
			}
		}
		return $out;
	}

	public static function send_email_default(): bool {
		return '1' === (string) get_option( self::OPTION_SEND_EMAIL, '0' );
	}

	public static function payment_label( string $slug ): string {
		return self::PAYMENT_METHODS[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Find an existing meal product matching a set, used as the default mapping
	 * so a fresh install mostly "just works". Standard/bulk match the meal tier;
	 * sweets matches a product with sweet mode enabled.
	 */
	private static function auto_detect_product( string $set ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$meta_query = ( 'sweets' === $set )
			? [ [ 'key' => '_fn_allow_sweet_mode', 'value' => '1' ] ]
			: [
				[ 'key' => '_fn_is_meal', 'value' => '1' ],
				[ 'key' => '_fn_meal_tier', 'value' => $set ],
			];
		$ids = wc_get_products(
			[
				'status'     => 'publish',
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
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
			$map = [];
			foreach ( self::SETS as $set ) {
				$map[ $set ] = isset( $_POST[ 'fn_product_' . $set ] ) ? (int) $_POST[ 'fn_product_' . $set ] : 0;
			}
			update_option( self::OPTION_PRODUCTS, $map, false );
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

		$products   = self::products();
		$screen_url = admin_url( 'admin.php?page=' . QuickOrderPage::ADMIN_SLUG );

		echo '<div class="wrap"><h1>' . esc_html__( 'Quick Order — Settings', 'fastnutrition-mealprep' ) . '</h1>';

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:820px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'Staff order screen', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'A fast, touch-first screen for staff to take phone and walk-in orders straight into WooCommerce. It lives in the WordPress admin and is available to Shop Managers and Administrators — orders are attributed to whoever is signed in.', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:8px 0 0"><a class="button button-primary" href="' . esc_url( $screen_url ) . '">' . esc_html__( 'Open Quick Order screen', 'fastnutrition-mealprep' ) . '</a></p>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Product sets', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Map the three screen tabs to your meal products. Defaults are auto-detected from the meal tier / sweet mode you set on each product.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_instore_settings', 'fn_instore_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_products" />';
		echo '<table class="form-table"><tbody>';
		$labels = [
			'standard' => __( 'Standard pick-and-mix product', 'fastnutrition-mealprep' ),
			'bulk'     => __( 'Bulk pick-and-mix product', 'fastnutrition-mealprep' ),
			'sweets'   => __( 'Sweets product', 'fastnutrition-mealprep' ),
		];
		foreach ( self::SETS as $set ) {
			echo '<tr><th>' . esc_html( $labels[ $set ] ) . '</th><td>';
			$this->render_product_select( 'fn_product_' . $set, $products[ $set ], 'sweets' === $set );
			echo '</td></tr>';
		}
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="fn_send_email_default" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Confirmation email', 'fastnutrition-mealprep' ),
			checked( self::send_email_default(), true, false ),
			esc_html__( 'Default the "send confirmation email" toggle on (only sends when an email address is entered).', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'fastnutrition-mealprep' ) );
		echo '</form>';

		echo '</div>';
	}

	private function render_product_select( string $name, int $current, bool $sweet_mode ): void {
		$meta_query = $sweet_mode
			? [ [ 'key' => '_fn_allow_sweet_mode', 'value' => '1' ] ]
			: [ [ 'key' => '_fn_is_meal', 'value' => '1' ] ];
		$ids = function_exists( 'wc_get_products' )
			? wc_get_products(
				[
					'status'     => 'publish',
					'limit'      => -1,
					'return'     => 'ids',
					'orderby'    => 'title',
					'order'      => 'ASC',
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]
			)
			: [];
		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html__( '— none —', 'fastnutrition-mealprep' ) . '</option>';
		foreach ( $ids as $pid ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $pid,
				selected( (int) $pid, $current, false ),
				esc_html( get_the_title( (int) $pid ) )
			);
		}
		echo '</select>';
	}
}

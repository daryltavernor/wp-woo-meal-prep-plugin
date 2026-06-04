<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * Settings + option storage for the In-Store Quick Order tool.
 *
 * Owns: the store-unlock password (hashed), the signed-token secret, the staff
 * PIN roster (hashed), the three product-set ids (standard / bulk / sweets), the
 * optional per-set ingredient override allow-lists for in-store offers, and the
 * default "send confirmation email" toggle.
 *
 * Payment methods and the paid/unpaid → status mapping are fixed product
 * decisions and live here as constants.
 */
final class InStoreSettings {

	public const OPTION_STORE_PW_HASH = 'fn_instore_store_pw_hash';
	public const OPTION_TOKEN_SECRET  = 'fn_instore_token_secret';
	public const OPTION_STAFF         = 'fn_instore_staff';
	public const OPTION_PRODUCTS      = 'fn_instore_products';
	public const OPTION_OVERRIDES     = 'fn_instore_overrides';
	public const OPTION_SEND_EMAIL    = 'fn_instore_send_email_default';

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

	public static function store_password_is_set(): bool {
		return '' !== (string) get_option( self::OPTION_STORE_PW_HASH, '' );
	}

	public static function store_password_hash(): string {
		return (string) get_option( self::OPTION_STORE_PW_HASH, '' );
	}

	/**
	 * The HMAC secret used to sign kiosk tokens. Generated on first read so an
	 * admin never has to think about it; rotating it (via "Sign out all devices")
	 * invalidates every issued token.
	 */
	public static function token_secret(): string {
		$secret = (string) get_option( self::OPTION_TOKEN_SECRET, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPTION_TOKEN_SECRET, $secret, false );
		}
		return $secret;
	}

	public static function rotate_token_secret(): void {
		update_option( self::OPTION_TOKEN_SECRET, wp_generate_password( 64, true, true ), false );
	}

	/**
	 * @return array<int,array{id:int,name:string,pin_hash:string}>
	 */
	public static function staff(): array {
		$raw = get_option( self::OPTION_STAFF, [] );
		return is_array( $raw ) ? array_values( $raw ) : [];
	}

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

		if ( 'save_access' === $action ) {
			$pw = isset( $_POST['fn_store_password'] ) ? (string) wp_unslash( $_POST['fn_store_password'] ) : '';
			if ( '' !== trim( $pw ) ) {
				update_option( self::OPTION_STORE_PW_HASH, wp_hash_password( $pw ), false );
			}
			set_transient( 'fn_instore_notice', __( 'Store access password saved.', 'fastnutrition-mealprep' ), 30 );
		} elseif ( 'sign_out_all' === $action ) {
			self::rotate_token_secret();
			set_transient( 'fn_instore_notice', __( 'All devices signed out. Staff must re-enter the store password.', 'fastnutrition-mealprep' ), 30 );
		} elseif ( 'save_staff' === $action ) {
			$this->save_staff();
			set_transient( 'fn_instore_notice', __( 'Staff & PINs saved.', 'fastnutrition-mealprep' ), 30 );
		} elseif ( 'save_products' === $action ) {
			$map = [];
			foreach ( self::SETS as $set ) {
				$map[ $set ] = isset( $_POST[ 'fn_product_' . $set ] ) ? (int) $_POST[ 'fn_product_' . $set ] : 0;
			}
			update_option( self::OPTION_PRODUCTS, $map, false );
			update_option( self::OPTION_SEND_EMAIL, ! empty( $_POST['fn_send_email_default'] ) ? '1' : '0', false );
			set_transient( 'fn_instore_notice', __( 'Product sets saved.', 'fastnutrition-mealprep' ), 30 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private function save_staff(): void {
		$names    = isset( $_POST['fn_staff_name'] ) && is_array( $_POST['fn_staff_name'] ) ? wp_unslash( $_POST['fn_staff_name'] ) : [];
		$pins     = isset( $_POST['fn_staff_pin'] ) && is_array( $_POST['fn_staff_pin'] ) ? wp_unslash( $_POST['fn_staff_pin'] ) : [];
		$existing = [];
		foreach ( self::staff() as $row ) {
			$existing[ (int) $row['id'] ] = $row;
		}
		$ids = isset( $_POST['fn_staff_id'] ) && is_array( $_POST['fn_staff_id'] ) ? array_map( 'intval', wp_unslash( $_POST['fn_staff_id'] ) ) : [];

		$out     = [];
		$next_id = 1;
		foreach ( $existing as $eid => $_row ) {
			$next_id = max( $next_id, $eid + 1 );
		}

		foreach ( $names as $i => $raw_name ) {
			$name = sanitize_text_field( (string) $raw_name );
			if ( '' === $name ) {
				continue;
			}
			$id  = isset( $ids[ $i ] ) ? (int) $ids[ $i ] : 0;
			$pin = isset( $pins[ $i ] ) ? preg_replace( '/\D/', '', (string) $pins[ $i ] ) : '';

			if ( $id && isset( $existing[ $id ] ) ) {
				$hash = '' !== $pin ? wp_hash_password( $pin ) : (string) $existing[ $id ]['pin_hash'];
			} else {
				if ( '' === $pin ) {
					continue; // New staff need a PIN.
				}
				$id   = $next_id++;
				$hash = wp_hash_password( $pin );
			}
			$out[] = [ 'id' => $id, 'name' => $name, 'pin_hash' => $hash ];
		}
		update_option( self::OPTION_STAFF, $out, false );
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

		$products = self::products();
		$staff    = self::staff();
		$page_url = home_url( '/?fn_quick_order=1' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Quick Order — Settings', 'fastnutrition-mealprep' ) . '</h1>';

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:820px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'In-store / phone order screen', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'A fast, touch-first screen for staff to take phone and walk-in orders straight into WooCommerce. Open it on the iPad, unlock once with the store password, and each order is attributed by staff PIN.', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:8px 0 0"><strong>' . esc_html__( 'Screen URL:', 'fastnutrition-mealprep' ) . '</strong> <code>' . esc_html( $page_url ) . '</code> ' . esc_html__( '(or place the [fn_quick_order] shortcode on any page).', 'fastnutrition-mealprep' ) . '</p>';
		echo '</div>';

		// Access password.
		echo '<h2>' . esc_html__( 'Store access', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_instore_settings', 'fn_instore_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_access" />';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th><label for="fn_store_password">%s</label></th><td><input type="password" id="fn_store_password" name="fn_store_password" class="regular-text" autocomplete="new-password" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Store password', 'fastnutrition-mealprep' ),
			esc_attr( self::store_password_is_set() ? __( '•••••••• (set — leave blank to keep)', 'fastnutrition-mealprep' ) : __( 'Set a password to unlock the screen', 'fastnutrition-mealprep' ) ),
			esc_html__( 'Entered once per device to unlock the screen. The device then stays unlocked indefinitely (it does NOT use a WordPress login, so it never gets logged out).', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save store password', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo '</form>';

		echo '<form method="post" style="margin-top:10px">';
		wp_nonce_field( 'fn_instore_settings', 'fn_instore_nonce' );
		echo '<input type="hidden" name="fn_action" value="sign_out_all" />';
		echo '<p class="description">' . esc_html__( 'Lost a device? Sign every device out and require the store password again.', 'fastnutrition-mealprep' ) . '</p>';
		submit_button( __( 'Sign out all devices', 'fastnutrition-mealprep' ), 'delete', '', false );
		echo '</form>';

		// Staff & PINs.
		echo '<h2>' . esc_html__( 'Staff & PINs', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each order requires a PIN that identifies the staff member who took it. The PIN is stored on the order for attribution. Leave a PIN blank when editing to keep the existing one.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_instore_settings', 'fn_instore_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_staff" />';
		echo '<table class="widefat striped" style="max-width:620px"><thead><tr><th>' . esc_html__( 'Name', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'PIN (digits)', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
		$rows = array_merge( $staff, [ [ 'id' => 0, 'name' => '', 'pin_hash' => '' ] ], [ [ 'id' => 0, 'name' => '', 'pin_hash' => '' ] ] );
		foreach ( $rows as $row ) {
			$has_pin = '' !== (string) ( $row['pin_hash'] ?? '' );
			printf(
				'<tr><td><input type="hidden" name="fn_staff_id[]" value="%1$d" /><input type="text" name="fn_staff_name[]" value="%2$s" class="regular-text" /></td><td><input type="text" inputmode="numeric" pattern="[0-9]*" name="fn_staff_pin[]" value="" placeholder="%3$s" /></td></tr>',
				(int) ( $row['id'] ?? 0 ),
				esc_attr( (string) ( $row['name'] ?? '' ) ),
				esc_attr( $has_pin ? __( '•••• (set)', 'fastnutrition-mealprep' ) : __( 'e.g. 1234', 'fastnutrition-mealprep' ) )
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Save staff & PINs', 'fastnutrition-mealprep' ) );
		echo '</form>';

		// Product sets.
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
		submit_button( __( 'Save product sets', 'fastnutrition-mealprep' ) );
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

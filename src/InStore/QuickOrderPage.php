<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * The Quick Order screen as a WordPress admin page.
 *
 * Lives under Meal Prep → Quick Order and is only reachable by users with the
 * `manage_woocommerce` capability (Shop Managers + Administrators). The screen
 * is the touch-first React app; access is governed entirely by the WordPress
 * login + capability, exactly like the plugin's other settings pages.
 */
final class QuickOrderPage {

	public const ADMIN_SLUG = 'fn-quick-order';
	private const HANDLE     = 'fn-quick-order';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	public static function render_admin_static(): void {
		( new self() )->render_admin();
	}

	/** Enqueue the app bundle only on our admin page. */
	public function maybe_enqueue( string $hook ): void {
		unset( $hook );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page routing, read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::ADMIN_SLUG !== $page ) {
			return;
		}

		$build = FN_MEALPREP_DIR . 'assets/build/quick-order/';
		$url   = FN_MEALPREP_URL . 'assets/build/quick-order/';

		$js = $build . 'index.js';
		if ( ! is_readable( $js ) ) {
			return;
		}
		$asset = is_readable( $build . 'index.asset.php' )
			? include $build . 'index.asset.php'
			: [ 'dependencies' => [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ], 'version' => FN_MEALPREP_VERSION ];

		wp_enqueue_script( self::HANDLE, $url . 'index.js', $asset['dependencies'], $asset['version'], true );
		if ( is_readable( $build . 'style-index.css' ) ) {
			wp_enqueue_style( self::HANDLE, $url . 'style-index.css', [], FN_MEALPREP_VERSION );
		}
		wp_add_inline_script(
			self::HANDLE,
			'window.fnQuickOrder = ' . wp_json_encode( $this->boot_data() ) . ';',
			'before'
		);
	}

	private function boot_data(): array {
		return [
			'restUrl'  => esc_url_raw( rest_url( 'fastnutrition/v1/instore/' ) ),
			'v1Url'    => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
			'slotsUrl' => esc_url_raw( rest_url( 'fastnutrition/v1/slots' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'exitUrl'  => esc_url_raw( admin_url() ),
			'currency' => self::currency_symbol(),
		];
	}

	/** WooCommerce returns the symbol as an HTML entity (e.g. &pound;); decode it. */
	private static function currency_symbol(): string {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return '£';
		}
		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	public function render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to take orders.', 'fastnutrition-mealprep' ) );
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Quick Order', 'fastnutrition-mealprep' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Quick Order assets are not built. Run "npm run build" in the plugin directory.', 'fastnutrition-mealprep' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap fn-quick-order-wrap">';
		echo '<a class="fn-qo-exit" href="' . esc_url( admin_url() ) . '" aria-label="' . esc_attr__( 'Close Quick Order', 'fastnutrition-mealprep' ) . '">&times;</a>';
		echo '<div id="fn-quick-order-root" class="fn-quick-order"></div>';
		echo '</div>';
	}
}

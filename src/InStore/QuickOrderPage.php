<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * The Quick Order and Quick Label Maker screens as WordPress admin pages.
 *
 * Both run the same touch-first React app (assets/build/quick-order) in a
 * full-screen modal, distinguished by a `mode` flag:
 *   - order : builds a real WooCommerce order (Meal Prep → Quick Order).
 *   - label : builds labels only, no order (Meal Prep → Quick Label Maker).
 *
 * Only users with the `manage_woocommerce` capability (Shop Managers +
 * Administrators) can reach either page.
 */
final class QuickOrderPage {

	public const ADMIN_SLUG = 'fn-quick-order';
	public const LABEL_SLUG = 'fn-quick-label';
	private const HANDLE     = 'fn-quick-order';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	public static function render_order_static(): void {
		( new self() )->render( __( 'Quick Order', 'fastnutrition-mealprep' ) );
	}

	public static function render_label_static(): void {
		( new self() )->render( __( 'Quick Label Maker', 'fastnutrition-mealprep' ) );
	}

	private function mode_for_page( string $page ): string {
		if ( self::LABEL_SLUG === $page ) {
			return 'label';
		}
		if ( self::ADMIN_SLUG === $page ) {
			return 'order';
		}
		return '';
	}

	/** Enqueue the app bundle only on our two admin pages. */
	public function maybe_enqueue( string $hook ): void {
		unset( $hook );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page routing, read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$mode = $this->mode_for_page( $page );
		if ( '' === $mode ) {
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
			'window.fnQuickOrder = ' . wp_json_encode( $this->boot_data( $mode ) ) . ';',
			'before'
		);
	}

	private function boot_data( string $mode ): array {
		return [
			'mode'      => $mode,
			'restUrl'   => esc_url_raw( rest_url( 'fastnutrition/v1/instore/' ) ),
			'v1Url'     => esc_url_raw( rest_url( 'fastnutrition/v1/' ) ),
			// In-store slots use the relaxed 23:55 cut-off (staff take orders all evening).
			'slotsUrl'  => esc_url_raw( rest_url( 'fastnutrition/v1/instore/slots' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'exitUrl'   => esc_url_raw( admin_url() ),
			'currency'  => self::currency_symbol(),
		];
	}

	/** WooCommerce returns the symbol as an HTML entity (e.g. &pound;); decode it. */
	private static function currency_symbol(): string {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return '£';
		}
		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	private function render( string $title ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this screen.', 'fastnutrition-mealprep' ) );
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Quick Order assets are not built. Run "npm run build" in the plugin directory.', 'fastnutrition-mealprep' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap fn-quick-order-wrap">';
		echo '<a class="fn-qo-exit" href="' . esc_url( admin_url() ) . '" aria-label="' . esc_attr__( 'Close', 'fastnutrition-mealprep' ) . '">&times;</a>';
		echo '<div id="fn-quick-order-root" class="fn-quick-order"></div>';
		echo '</div>';
	}
}

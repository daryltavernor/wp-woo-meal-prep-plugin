<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\InStore;

/**
 * Front-end shell for the Quick Order screen.
 *
 * Two ways in:
 *   - Full-screen kiosk URL:  /?fn_quick_order=1  (a clean standalone page with
 *     only this app's assets — no theme chrome — ideal on the iPad).
 *   - Shortcode [fn_quick_order] for embedding inside a normal page if preferred.
 *
 * The page itself is public; access is gated by the store-password unlock + the
 * signed kiosk token (see KioskAuth) enforced on the REST endpoints, not by a
 * WordPress login.
 */
final class QuickOrderPage {

	private const HANDLE   = 'fn-quick-order';
	private const QUERY_VAR = 'fn_quick_order';

	public function register(): void {
		add_action( 'init', [ $this, 'register_assets' ] );
		add_shortcode( 'fn_quick_order', [ $this, 'shortcode' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_fullscreen' ] );
	}

	public function register_assets(): void {
		$build = FN_MEALPREP_DIR . 'assets/build/quick-order/';
		$url   = FN_MEALPREP_URL . 'assets/build/quick-order/';

		$js = $build . 'index.js';
		if ( is_readable( $js ) ) {
			$asset = is_readable( $build . 'index.asset.php' )
				? include $build . 'index.asset.php'
				: [ 'dependencies' => [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ], 'version' => FN_MEALPREP_VERSION ];
			wp_register_script( self::HANDLE, $url . 'index.js', $asset['dependencies'], $asset['version'], true );
		}
		if ( is_readable( $build . 'style-index.css' ) ) {
			wp_register_style( self::HANDLE, $url . 'style-index.css', [], FN_MEALPREP_VERSION );
		}
	}

	private function boot_data(): array {
		return [
			'restUrl'  => esc_url_raw( rest_url( 'fastnutrition/v1/instore/' ) ),
			'slotsUrl' => esc_url_raw( rest_url( 'fastnutrition/v1/slots' ) ),
			'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£',
		];
	}

	private function enqueue(): void {
		wp_enqueue_script( self::HANDLE );
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
		wp_add_inline_script(
			self::HANDLE,
			'window.fnQuickOrder = ' . wp_json_encode( $this->boot_data() ) . ';',
			'before'
		);
	}

	public function shortcode(): string {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return '<div class="fn-quick-order-missing">' . esc_html__( 'Quick Order assets are not built yet. Run "npm run build" in the plugin directory.', 'fastnutrition-mealprep' ) . '</div>';
		}
		$this->enqueue();
		return '<div id="fn-quick-order-root" class="fn-quick-order"></div>';
	}

	public function maybe_render_fullscreen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page switch, no state change.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		nocache_headers();
		$this->enqueue();

		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="mobile-web-app-capable" content="yes" />
	<title><?php esc_html_e( 'Quick Order', 'fastnutrition-mealprep' ); ?></title>
		<?php
		wp_print_styles( self::HANDLE );
		?>
</head>
<body class="fn-quick-order-fullscreen">
	<div id="fn-quick-order-root" class="fn-quick-order"></div>
		<?php
		wp_print_scripts( self::HANDLE );
		?>
</body>
</html>
		<?php
		exit;
	}
}

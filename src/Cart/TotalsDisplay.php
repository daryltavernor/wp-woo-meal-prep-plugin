<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\Admin\SettingsPage;

final class TotalsDisplay {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		// Shortcode cart: inject rows just above the order total.
		add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_classic_cart_rows' ] );
		// Shortcode checkout: inject in the order review table.
		add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_classic_cart_rows' ] );
		// Hide shipping from the cart page totals (data-level, theme-independent).
		add_action( 'wp', [ $this, 'maybe_hide_cart_shipping' ] );
	}

	public function maybe_hide_cart_shipping(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( is_cart() && ! is_checkout() ) {
			add_filter( 'woocommerce_cart_ready_to_calc_shipping', '__return_false', 99 );
		}
	}

	/**
	 * Compute bundle savings and add-on total for the current cart.
	 * Single source of truth — used by both the Store API extension (block
	 * cart/checkout) and the WC hook renderer (classic shortcode cart).
	 *
	 * @return array{addon_total: float, bundle_savings: float}
	 */
	public static function compute_summary(): array {
		$addon_total    = 0.0;
		$bundle_savings = 0.0;
		$bundle_seen    = [];

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return [ 'addon_total' => 0.0, 'bundle_savings' => 0.0 ];
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$selection = $item[ Selections::CART_KEY ] ?? null;
			$qty       = (float) ( $item['quantity'] ?? 0 );

			if ( is_array( $selection ) ) {
				foreach ( ( $selection['addons'] ?? [] ) as $addon ) {
					$addon_total += (float) ( $addon['price'] ?? 0 ) * $qty;
				}
			}

			$bundle = $item['fn_bundle'] ?? null;
			if ( is_array( $bundle ) && ! empty( $bundle['applied_tier'] ) ) {
				$product_id = (int) $item['product_id'];
				if ( ! isset( $bundle_seen[ $product_id ] ) ) {
					$bundle_seen[ $product_id ] = true;
					$catalog                    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
					$base                       = $catalog ? (float) $catalog->get_price( 'edit' ) : 0.0;
					$bundled_qty                = (int) ( $bundle['bundle_units'] ?? 0 );
					$bundle_total               = (float) ( $bundle['bundle_total'] ?? 0 );
					$bundle_savings            += max( 0.0, ( $base * $bundled_qty ) - $bundle_total );
				}
			}
		}

		return [
			'addon_total'    => $addon_total,
			'bundle_savings' => $bundle_savings,
		];
	}

	public function render_classic_cart_rows(): void {
		$data = self::compute_summary();
		if ( $data['bundle_savings'] > 0.0001 ) {
			?>
			<tr class="fn-cart-row fn-cart-savings">
				<th><?php esc_html_e( 'You saved (bundle)', 'fastnutrition-mealprep' ); ?></th>
				<td data-title="<?php esc_attr_e( 'You saved (bundle)', 'fastnutrition-mealprep' ); ?>"><?php echo wp_kses_post( wc_price( -1 * $data['bundle_savings'] ) ); ?></td>
			</tr>
			<?php
		}
		if ( $data['addon_total'] > 0.0001 ) {
			?>
			<tr class="fn-cart-row fn-cart-addons">
				<th><?php esc_html_e( 'Add-ons total', 'fastnutrition-mealprep' ); ?></th>
				<td data-title="<?php esc_attr_e( 'Add-ons total', 'fastnutrition-mealprep' ); ?>"><?php echo wp_kses_post( wc_price( $data['addon_total'] ) ); ?></td>
			</tr>
			<?php
		}
	}

	public function enqueue_frontend(): void {
		if ( ! $this->page_is_relevant() ) {
			return;
		}

		$build = FN_MEALPREP_DIR . 'assets/build/blocks/cart-totals-extras/';
		$url   = FN_MEALPREP_URL . 'assets/build/blocks/cart-totals-extras/';

		$view_js = $build . 'view.js';
		if ( ! is_readable( $view_js ) ) {
			return;
		}

		$asset = is_readable( $build . 'view.asset.php' )
			? include $build . 'view.asset.php'
			: [ 'dependencies' => [ 'wp-data', 'wp-i18n' ], 'version' => FN_MEALPREP_VERSION ];

		wp_enqueue_script(
			'fn-cart-totals-extras',
			$url . 'view.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? FN_MEALPREP_VERSION,
			true
		);

		if ( is_readable( $build . 'style-view.css' ) && ! SettingsPage::minimal_styling() ) {
			wp_enqueue_style(
				'fn-cart-totals-extras',
				$url . 'style-view.css',
				[],
				FN_MEALPREP_VERSION
			);
		}
	}

	private function page_is_relevant(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		// Always enqueue on the WC-active frontend. is_cart()/is_checkout()
		// and has_block($post) both miss when the cart/checkout block is
		// rendered from a Site Editor template part or when the page isn't
		// the one set in WC Settings → Advanced. The CSS only matches
		// specific WC-block classes, and the JS has its own DOM guards,
		// so loading on every page is safe and ~2 KB.
		return true;
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Install\IngredientSeeder;

final class SettingsPage {

	public const OPTION_MULTISTEP_ENABLED = 'fn_multistep_enabled';
	public const OPTION_CREATE_CALC_PAGE  = 'fn_macro_calc_page_id';
	public const OPTION_MINIMAL_STYLING   = 'fn_minimal_styling';
	public const OPTION_BUILDER_PLACEMENT = 'fn_builder_placement';

	/**
	 * @return array<string,array{label:string,hook:string,priority:int}>
	 */
	public static function placements(): array {
		return [
			'replace_add_to_cart' => [ 'label' => 'Replace the Add-to-Cart button', 'hook' => '', 'priority' => 0 ],
			'before_add_to_cart'  => [ 'label' => 'Before the Add-to-Cart button', 'hook' => 'woocommerce_before_add_to_cart_form', 'priority' => 10 ],
			'after_add_to_cart'   => [ 'label' => 'After the Add-to-Cart button', 'hook' => 'woocommerce_after_add_to_cart_form', 'priority' => 10 ],
			'after_short_desc'    => [ 'label' => 'After the short description', 'hook' => 'woocommerce_single_product_summary', 'priority' => 21 ],
			'after_title'         => [ 'label' => 'After the product title', 'hook' => 'woocommerce_single_product_summary', 'priority' => 6 ],
			'shortcode'           => [ 'label' => 'Manual — only via [fn_meal_builder] shortcode', 'hook' => '', 'priority' => 0 ],
		];
	}

	public static function get_placement(): string {
		$val = (string) get_option( self::OPTION_BUILDER_PLACEMENT, 'replace_add_to_cart' );
		return isset( self::placements()[ $val ] ) ? $val : 'replace_add_to_cart';
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function handle_actions(): void {
		if ( ! isset( $_POST['fn_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_settings_nonce'] ) ), 'fn_save_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['fn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_action'] ) ) : '';
		if ( 'save' === $action ) {
			update_option( self::OPTION_MULTISTEP_ENABLED, ! empty( $_POST['fn_multistep_enabled'] ) ? 1 : 0 );
			update_option( self::OPTION_MINIMAL_STYLING, ! empty( $_POST['fn_minimal_styling'] ) ? 1 : 0 );
			$placement = isset( $_POST['fn_builder_placement'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_builder_placement'] ) ) : 'replace_add_to_cart';
			update_option( self::OPTION_BUILDER_PLACEMENT, isset( self::placements()[ $placement ] ) ? $placement : 'replace_add_to_cart' );
		} elseif ( 'convert_checkout' === $action ) {
			$result = self::convert_checkout_page_to_blocks();
			set_transient( 'fn_settings_notice', $result, 30 );
		} elseif ( 'create_calc_page' === $action ) {
			$page_id = self::ensure_macro_calculator_page();
			set_transient( 'fn_settings_notice', 'Macro Calculator page created (ID ' . $page_id . ').', 30 );
		} elseif ( 'seed_ingredients' === $action ) {
			$count = IngredientSeeder::seed( true );
			set_transient(
				'fn_settings_notice',
				sprintf( 'Seeded %d ingredients (existing entries skipped).', $count ),
				30
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fn-settings' ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$notice = get_transient( 'fn_settings_notice' );
		if ( $notice ) {
			delete_transient( 'fn_settings_notice' );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}

		$enabled      = '' === get_option( self::OPTION_MULTISTEP_ENABLED, '1' ) ? true : (bool) get_option( self::OPTION_MULTISTEP_ENABLED, '1' );
		$minimal      = (bool) get_option( self::OPTION_MINIMAL_STYLING, '0' );
		$checkout_id  = (int) wc_get_page_id( 'checkout' );
		$checkout     = $checkout_id > 0 ? get_post( $checkout_id ) : null;
		$using_blocks = $checkout && has_block( 'woocommerce/checkout', $checkout );
		$using_short  = $checkout && false !== strpos( (string) $checkout->post_content, '[woocommerce_checkout' );
		$calc_page_id = (int) get_option( self::OPTION_CREATE_CALC_PAGE, 0 );

		echo '<div class="wrap"><h1>' . esc_html__( 'Meal Prep — Settings', 'fastnutrition-mealprep' ) . '</h1>';

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page does', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Toggles the multi-step checkout flow, lets you convert a legacy (shortcode) checkout page to the Blocks version with one click, and can auto-create a page hosting the Macro Calculator.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		// Checkout status summary.
		echo '<h2>' . esc_html__( 'Checkout flow', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:780px"><tbody>';
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Checkout page', 'fastnutrition-mealprep' ),
			$checkout ? '<a href="' . esc_url( get_permalink( $checkout ) ) . '" target="_blank">' . esc_html( $checkout->post_title ) . '</a> — <a href="' . esc_url( get_edit_post_link( $checkout->ID ) ) . '">' . esc_html__( 'edit', 'fastnutrition-mealprep' ) . '</a>' : esc_html__( 'Not set (configure under WooCommerce → Settings → Advanced)', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Currently using', 'fastnutrition-mealprep' ),
			$using_blocks ? '<span style="color:#006400">✓ ' . esc_html__( 'Blocks (multi-step works automatically)', 'fastnutrition-mealprep' ) . '</span>' : ( $using_short ? '<span style="color:#b34">⚠ ' . esc_html__( 'Old [woocommerce_checkout] shortcode — multi-step will NOT apply until converted', 'fastnutrition-mealprep' ) . '</span>' : esc_html__( 'Unknown', 'fastnutrition-mealprep' ) )
		);
		echo '</tbody></table>';

		if ( $checkout && $using_short ) {
			echo '<form method="post" style="margin-top:1em">';
			wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
			echo '<input type="hidden" name="fn_action" value="convert_checkout" />';
			echo '<p>' . esc_html__( 'Click below to replace the shortcode with the Checkout block. Your old content is kept as a page revision so you can restore it if needed.', 'fastnutrition-mealprep' ) . '</p>';
			submit_button( __( 'Convert checkout page to Blocks', 'fastnutrition-mealprep' ), 'primary', '', false );
			echo '</form>';
		}

		echo '<form method="post" style="margin-top:1.5em">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="save" />';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="fn_multistep_enabled" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Enable multi-step checkout', 'fastnutrition-mealprep' ),
			checked( $enabled, true, false ),
			esc_html__( 'Group the checkout into Address → Delivery/Collection → Payment', 'fastnutrition-mealprep' ),
			esc_html__( 'When enabled, the plugin transparently groups the native WooCommerce Checkout block into three steps. No manual block placement needed. Disable this to fall back to the standard single-page Woo checkout.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="fn_minimal_styling" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Inherit theme styling (minimal mode)', 'fastnutrition-mealprep' ),
			checked( $minimal, true, false ),
			esc_html__( 'Turn off plugin CSS and render the meal builder as native dropdowns', 'fastnutrition-mealprep' ),
			esc_html__( 'Off (default): the meal builder, macro calculator, slot picker, and multi-step nav use the plugin\'s built-in black + lime pill styling. On: plugin stylesheets are not enqueued and the meal builder renders using <select> dropdowns — exactly like your existing Fast Nutrition site — so your Flatsome theme CSS controls every colour, font, and button.', 'fastnutrition-mealprep' )
		);

		$current_placement = self::get_placement();
		echo '<tr><th>' . esc_html__( 'Meal builder placement', 'fastnutrition-mealprep' ) . '</th><td><select name="fn_builder_placement">';
		foreach ( self::placements() as $key => $row ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $current_placement, $key, false ), esc_html( $row['label'] ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Where the builder appears on meal products. If your theme or another plugin (Flatsome, Extra Product Options, Yith add-ons, etc.) is overriding the Add-to-Cart area, try "Before/After the Add-to-Cart button". Use "Manual" + the [fn_meal_builder] shortcode to drop it anywhere in the product description or a custom block.', 'fastnutrition-mealprep' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p class="description"><strong>' . esc_html__( 'Shortcode:', 'fastnutrition-mealprep' ) . '</strong> <code>[fn_meal_builder]</code> ' . esc_html__( 'inside a meal product description, or', 'fastnutrition-mealprep' ) . ' <code>[fn_meal_builder product_id="123"]</code> ' . esc_html__( 'anywhere on the site to render it for that specific product.', 'fastnutrition-mealprep' ) . '</p>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Macro calculator page', 'fastnutrition-mealprep' ) . '</h2>';
		if ( $calc_page_id && get_post( $calc_page_id ) ) {
			echo '<p>' . esc_html__( 'Page already created:', 'fastnutrition-mealprep' ) . ' <a href="' . esc_url( get_permalink( $calc_page_id ) ) . '" target="_blank">' . esc_html( get_the_title( $calc_page_id ) ) . '</a></p>';
		} else {
			echo '<form method="post">';
			wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
			echo '<input type="hidden" name="fn_action" value="create_calc_page" />';
			echo '<p>' . esc_html__( 'Create a page with the Macro Calculator already embedded (short-code or block). You can always add it manually with [fn_macro_calculator] on any page.', 'fastnutrition-mealprep' ) . '</p>';
			submit_button( __( 'Create Macro Calculator page', 'fastnutrition-mealprep' ), 'secondary', '', false );
			echo '</form>';
		}

		echo '<h2>' . esc_html__( 'Ingredient catalogue', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="seed_ingredients" />';
		echo '<p>' . esc_html__( 'Re-imports the Fast Nutrition starter ingredient catalogue (proteins, carbs, greens, set meals, sweets — both Standard and Bulk tiers — with macros). Existing ingredients with the same name are skipped, so this is safe to run multiple times.', 'fastnutrition-mealprep' ) . '</p>';
		submit_button( __( 'Import starter ingredients', 'fastnutrition-mealprep' ), 'secondary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Third-party checkout compatibility', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p>' . esc_html__( 'The multi-step flow is a layer on top of the native WooCommerce Checkout block — it does not replace the block. Any add-on that registers against the Checkout block (upsells, cross-sell widgets, extra field blocks, etc.) will continue to render. Unknown blocks default to Step 1 (Your details); if you have a plugin whose UI needs to appear during Payment, contact support to add its selector to the step map.', 'fastnutrition-mealprep' ) . '</p>';

		echo '</div>';
	}

	public static function convert_checkout_page_to_blocks(): string {
		$id = (int) wc_get_page_id( 'checkout' );
		if ( $id <= 0 ) {
			return 'No checkout page configured.';
		}
		$page = get_post( $id );
		if ( ! $page ) {
			return 'Checkout page not found.';
		}
		$content = (string) $page->post_content;
		if ( has_block( 'woocommerce/checkout', $page ) ) {
			return 'Checkout page already uses the Checkout block — multi-step applies automatically.';
		}
		if ( false === strpos( $content, '[woocommerce_checkout' ) ) {
			return 'Checkout page content unrecognised; no change made.';
		}
		$new = preg_replace( '/\[woocommerce_checkout[^\]]*\]/i', "<!-- wp:woocommerce/checkout /-->", $content );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $new,
			]
		);
		return 'Checkout page converted to Blocks. Previous content saved as a revision.';
	}

	public static function ensure_macro_calculator_page(): int {
		$existing = (int) get_option( self::OPTION_CREATE_CALC_PAGE, 0 );
		if ( $existing && get_post( $existing ) ) {
			return $existing;
		}
		$id = wp_insert_post(
			[
				'post_title'   => __( 'Macro Calculator', 'fastnutrition-mealprep' ),
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => "<!-- wp:fastnutrition/macro-calculator /-->",
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_option( self::OPTION_CREATE_CALC_PAGE, (int) $id, false );
		return (int) $id;
	}

	public static function multistep_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_MULTISTEP_ENABLED, '1' );
	}

	public static function minimal_styling(): bool {
		return '1' === (string) get_option( self::OPTION_MINIMAL_STYLING, '0' );
	}
}

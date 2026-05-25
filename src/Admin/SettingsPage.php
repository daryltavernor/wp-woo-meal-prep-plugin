<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\Surcharge;
use FastNutrition\MealPrep\Install\IngredientSeeder;

final class SettingsPage {

	public const OPTION_MULTISTEP_ENABLED = 'fn_multistep_enabled';
	public const OPTION_CREATE_CALC_PAGE  = 'fn_macro_calc_page_id';
	public const OPTION_MINIMAL_STYLING   = 'fn_minimal_styling';
	public const OPTION_BUILDER_PLACEMENT = 'fn_builder_placement';
	public const OPTION_UPDATE_BRANCH     = 'fn_update_branch';
	public const OPTION_UPDATE_TOKEN      = 'fn_update_token';
	public const OPTION_BRAND_LOGO_ID     = 'fn_brand_logo_id';
	public const OPTION_BRAND_WEB         = 'fn_brand_web';
	public const OPTION_BRAND_EMAIL       = 'fn_brand_email';
	public const OPTION_BRAND_PHONE       = 'fn_brand_phone';
	public const OPTION_BRAND_ADDRESS     = 'fn_brand_address';
	public const OPTION_ORDER_CUTOFF      = 'fn_order_cutoff';

	/**
	 * Daily cut-off time (HH:MM, WordPress local timezone) after which the
	 * next day's delivery / collection slots disappear from the customer's
	 * picker. Returns '' if cut-off is disabled.
	 */
	public static function order_cutoff(): string {
		$raw = trim( (string) get_option( self::OPTION_ORDER_CUTOFF, '18:00' ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $raw ) ) {
			return '18:00';
		}
		[ $h, $m ] = explode( ':', $raw );
		return sprintf( '%02d:%02d', (int) $h, (int) $m );
	}

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

			$branch = isset( $_POST['fn_update_branch'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fn_update_branch'] ) ) : '';
			update_option( self::OPTION_UPDATE_BRANCH, $branch !== '' ? $branch : 'main' );
			if ( isset( $_POST['fn_update_token'] ) ) {
				update_option( self::OPTION_UPDATE_TOKEN, sanitize_text_field( wp_unslash( (string) $_POST['fn_update_token'] ) ) );
			}
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
		} elseif ( 'save_surcharge' === $action ) {
			update_option( Surcharge::OPTION_ENABLED, ! empty( $_POST['fn_surcharge_enabled'] ) ? '1' : '0' );
			update_option( Surcharge::OPTION_THRESHOLD, isset( $_POST['fn_surcharge_threshold'] ) ? (float) wp_unslash( (string) $_POST['fn_surcharge_threshold'] ) : 23 );
			update_option( Surcharge::OPTION_AMOUNT, isset( $_POST['fn_surcharge_amount'] ) ? (float) wp_unslash( (string) $_POST['fn_surcharge_amount'] ) : 8 );
			update_option( Surcharge::OPTION_LABEL, isset( $_POST['fn_surcharge_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fn_surcharge_label'] ) ) : 'Basket surcharge' );
			set_transient( 'fn_settings_notice', __( 'Surcharge settings saved.', 'fastnutrition-mealprep' ), 30 );
		} elseif ( 'save_brand' === $action ) {
			update_option( self::OPTION_BRAND_LOGO_ID, isset( $_POST['fn_brand_logo_id'] ) ? (int) $_POST['fn_brand_logo_id'] : 0 );
			update_option( self::OPTION_BRAND_WEB, isset( $_POST['fn_brand_web'] ) ? esc_url_raw( wp_unslash( (string) $_POST['fn_brand_web'] ) ) : '' );
			update_option( self::OPTION_BRAND_EMAIL, isset( $_POST['fn_brand_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['fn_brand_email'] ) ) : '' );
			update_option( self::OPTION_BRAND_PHONE, isset( $_POST['fn_brand_phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fn_brand_phone'] ) ) : '' );
			update_option( self::OPTION_BRAND_ADDRESS, isset( $_POST['fn_brand_address'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['fn_brand_address'] ) ) : '' );
			set_transient( 'fn_settings_notice', __( 'Brand details saved.', 'fastnutrition-mealprep' ), 30 );
		} elseif ( 'save_cutoff' === $action ) {
			$raw = isset( $_POST['fn_order_cutoff'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fn_order_cutoff'] ) ) : '';
			$raw = trim( $raw );
			if ( '' !== $raw && ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $raw ) ) {
				$raw = '18:00';
			}
			update_option( self::OPTION_ORDER_CUTOFF, $raw );
			set_transient( 'fn_settings_notice', __( 'Ordering window saved.', 'fastnutrition-mealprep' ), 30 );
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

		// Self-updates from GitHub.
		$branch = (string) get_option( self::OPTION_UPDATE_BRANCH, 'main' );
		$token  = (string) get_option( self::OPTION_UPDATE_TOKEN, '' );
		echo '<h2>' . esc_html__( 'Plugin updates', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p>' . esc_html__( 'WordPress checks the GitHub repository for new versions in the background. When the Version header in fastnutrition-mealprep.php is bumped on the branch below and pushed, the standard WP "Update available" notice appears in Plugins and Dashboard → Updates.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="save" />';
		// Re-submit the other current values so this form doesn\'t reset them.
		echo '<input type="hidden" name="fn_multistep_enabled" value="' . ( self::multistep_enabled() ? '1' : '0' ) . '" />';
		echo '<input type="hidden" name="fn_minimal_styling" value="' . ( self::minimal_styling() ? '1' : '0' ) . '" />';
		echo '<input type="hidden" name="fn_builder_placement" value="' . esc_attr( self::get_placement() ) . '" />';

		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th><label for="fn_update_branch">%s</label></th><td><input type="text" id="fn_update_branch" name="fn_update_branch" value="%s" class="regular-text" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Update branch', 'fastnutrition-mealprep' ),
			esc_attr( $branch ),
			esc_html__( 'GitHub branch to watch for new versions. Defaults to "main". After pushing a commit that bumps the Version header, WordPress will see the update within ~12 hours, or use "Check again" on Plugins → Updates to fetch immediately.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th><label for="fn_update_token">%s</label></th><td><input type="password" id="fn_update_token" name="fn_update_token" value="%s" class="regular-text" autocomplete="new-password" /><p class="description">%s</p></td></tr>',
			esc_html__( 'GitHub access token (private repos only)', 'fastnutrition-mealprep' ),
			esc_attr( $token ),
			esc_html__( 'Only needed if the repository is private. Create a fine-grained Personal Access Token with read access to the repo and paste it here. Leave blank for public repos. Tokens are stored in wp_options and never sent to the front-end.', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save update settings', 'fastnutrition-mealprep' ) );
		echo '</form>';
		submit_button();
		echo '</form>';

		// Basket surcharge — encourage minimum-spend behaviour.
		$s_enabled   = Surcharge::enabled();
		$s_threshold = Surcharge::threshold();
		$s_amount    = Surcharge::amount();
		$s_label     = Surcharge::label();
		echo '<h2>' . esc_html__( 'Basket surcharge', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Adds a flat fee to the cart and checkout if the subtotal is below the threshold. The cart page shows a friendly note inviting the customer to spend more so they can skip the fee.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_surcharge" />';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="fn_surcharge_enabled" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Enable surcharge', 'fastnutrition-mealprep' ),
			checked( $s_enabled, true, false ),
			esc_html__( 'Apply when subtotal is below the threshold', 'fastnutrition-mealprep' )
		);
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£';
		printf(
			'<tr><th><label for="fn_surcharge_threshold">%s</label></th><td>%s <input type="number" id="fn_surcharge_threshold" name="fn_surcharge_threshold" value="%s" step="0.01" min="0" class="small-text" /> <p class="description">%s</p></td></tr>',
			esc_html__( 'Threshold', 'fastnutrition-mealprep' ),
			esc_html( $currency ),
			esc_attr( (string) $s_threshold ),
			esc_html__( 'Surcharge is added when the cart subtotal (before fees and shipping) is under this amount.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th><label for="fn_surcharge_amount">%s</label></th><td>%s <input type="number" id="fn_surcharge_amount" name="fn_surcharge_amount" value="%s" step="0.01" min="0" class="small-text" /></td></tr>',
			esc_html__( 'Surcharge amount', 'fastnutrition-mealprep' ),
			esc_html( $currency ),
			esc_attr( (string) $s_amount )
		);
		printf(
			'<tr><th><label for="fn_surcharge_label">%s</label></th><td><input type="text" id="fn_surcharge_label" name="fn_surcharge_label" value="%s" class="regular-text" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Label shown in cart totals', 'fastnutrition-mealprep' ),
			esc_attr( $s_label ),
			esc_attr__( 'Basket surcharge', 'fastnutrition-mealprep' ),
			esc_html__( 'The label customers see on the totals row.', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save surcharge settings', 'fastnutrition-mealprep' ) );
		echo '</form>';

		// Brand info (used on printed labels + emails).
		$brand        = self::brand_info();
		$logo_id      = $brand['logo_id'];
		$logo_preview = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		wp_enqueue_media();
		echo '<h2>' . esc_html__( 'Branding (labels & emails)', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These details are printed on every label (meal labels + order summaries) and used wherever the plugin needs to reference your business.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_brand" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Logo', 'fastnutrition-mealprep' ) . '</th><td>';
		echo '<input type="hidden" id="fn_brand_logo_id" name="fn_brand_logo_id" value="' . (int) $logo_id . '" />';
		echo '<div id="fn-brand-logo-preview" style="margin-bottom:8px;min-height:48px">';
		if ( $logo_preview ) {
			echo '<img src="' . esc_url( $logo_preview ) . '" style="max-width:240px;max-height:120px;border:1px solid #ddd;padding:4px;background:#fff" />';
		}
		echo '</div>';
		echo '<button type="button" class="button" id="fn-brand-logo-choose">' . esc_html__( 'Choose image', 'fastnutrition-mealprep' ) . '</button> ';
		echo '<button type="button" class="button" id="fn-brand-logo-remove"' . disabled( 0 === $logo_id, true, false ) . '>' . esc_html__( 'Remove', 'fastnutrition-mealprep' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Recommended: a square or wide-rectangle PNG/SVG with a transparent background. Will be scaled to fit the label header.', 'fastnutrition-mealprep' ) . '</p>';
		echo '</td></tr>';
		printf(
			'<tr><th><label for="fn_brand_web">%s</label></th><td><input type="url" id="fn_brand_web" name="fn_brand_web" value="%s" class="regular-text" placeholder="https://www.fastnutrition.co.uk" /></td></tr>',
			esc_html__( 'Web address', 'fastnutrition-mealprep' ),
			esc_attr( $brand['web'] )
		);
		printf(
			'<tr><th><label for="fn_brand_email">%s</label></th><td><input type="email" id="fn_brand_email" name="fn_brand_email" value="%s" class="regular-text" placeholder="hello@fastnutrition.co.uk" /></td></tr>',
			esc_html__( 'Email', 'fastnutrition-mealprep' ),
			esc_attr( $brand['email'] )
		);
		printf(
			'<tr><th><label for="fn_brand_phone">%s</label></th><td><input type="text" id="fn_brand_phone" name="fn_brand_phone" value="%s" class="regular-text" placeholder="07712 345 678" /></td></tr>',
			esc_html__( 'Phone', 'fastnutrition-mealprep' ),
			esc_attr( $brand['phone'] )
		);
		printf(
			'<tr><th><label for="fn_brand_address">%s</label></th><td><textarea id="fn_brand_address" name="fn_brand_address" rows="3" class="large-text" placeholder="Unit 4, Some Industrial Estate, Stoke ST3 4EY">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Physical address', 'fastnutrition-mealprep' ),
			esc_textarea( $brand['address'] ),
			esc_html__( 'One line per row. Used as your return address on labels.', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save brand details', 'fastnutrition-mealprep' ) );
		echo '</form>';
		?>
		<script>
		jQuery(function($){
			var frame;
			$('#fn-brand-logo-choose').on('click', function(e){
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({ title: 'Choose Logo', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
				frame.on('select', function(){
					var att = frame.state().get('selection').first().toJSON();
					$('#fn_brand_logo_id').val(att.id);
					$('#fn-brand-logo-preview').html('<img src="' + att.url + '" style="max-width:240px;max-height:120px;border:1px solid #ddd;padding:4px;background:#fff" />');
					$('#fn-brand-logo-remove').prop('disabled', false);
				});
				frame.open();
			});
			$('#fn-brand-logo-remove').on('click', function(e){
				e.preventDefault();
				$('#fn_brand_logo_id').val('0');
				$('#fn-brand-logo-preview').html('');
				$(this).prop('disabled', true);
			});
		});
		</script>
		<?php

		// Ordering window (next-day cut-off).
		$cutoff      = self::order_cutoff();
		$tz_label    = wp_timezone()->getName();
		$now_local   = wp_date( 'H:i' );
		echo '<h2>' . esc_html__( 'Ordering window', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p class="description">';
		printf(
			/* translators: 1: WP timezone identifier, 2: current local time */
			esc_html__( 'Times use your WordPress timezone (%1$s — currently %2$s).', 'fastnutrition-mealprep' ),
			'<strong>' . esc_html( $tz_label ) . '</strong>',
			'<strong>' . esc_html( $now_local ) . '</strong>'
		);
		echo '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_settings', 'fn_settings_nonce' );
		echo '<input type="hidden" name="fn_action" value="save_cutoff" />';
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th><label for="fn_order_cutoff">%s</label></th><td><input type="time" id="fn_order_cutoff" name="fn_order_cutoff" value="%s" style="max-width:140px" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Daily cut-off time', 'fastnutrition-mealprep' ),
			esc_attr( $cutoff ),
			esc_html__( 'After this time the NEXT day\'s delivery and collection slots disappear from the customer\'s picker. Example: set to 18:00 and a customer trying to order at 18:01 on Monday will see Wednesday as the earliest available date, not Tuesday. Leave blank to disable the cut-off (only the standard one-day lead time applies).', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save ordering window', 'fastnutrition-mealprep' ) );
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

	/**
	 * Brand info used on labels and other branded surfaces.
	 *
	 * @return array{logo_id:int,logo_url:string,logo_path:string,web:string,email:string,phone:string,address:string}
	 */
	public static function brand_info(): array {
		$logo_id   = (int) get_option( self::OPTION_BRAND_LOGO_ID, 0 );
		$logo_url  = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$logo_path = $logo_id ? (string) get_attached_file( $logo_id ) : '';
		return [
			'logo_id'   => $logo_id,
			'logo_url'  => $logo_url,
			'logo_path' => $logo_path && is_readable( $logo_path ) ? $logo_path : '',
			'web'       => (string) get_option( self::OPTION_BRAND_WEB, '' ),
			'email'     => (string) get_option( self::OPTION_BRAND_EMAIL, '' ),
			'phone'     => (string) get_option( self::OPTION_BRAND_PHONE, '' ),
			'address'   => (string) get_option( self::OPTION_BRAND_ADDRESS, '' ),
		];
	}
}

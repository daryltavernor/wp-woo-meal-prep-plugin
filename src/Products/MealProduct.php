<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Post;

// StandaloneProduct lives in this same namespace; referenced for the shared
// "is this product configurable on the front end?" gate.

final class MealProduct {

	public const TAB_ID = 'fn_meal_builder';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		add_action( 'woocommerce_process_product_meta_simple', [ $this, 'save' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
		add_action( 'wp', [ $this, 'apply_placement' ] );
		add_shortcode( 'fn_meal_builder', [ $this, 'shortcode' ] );
		add_shortcode( 'fn_macros', [ $this, 'macros_shortcode' ] );
	}

	/**
	 * [fn_macros] — placeholder where the current meal's live macros are rendered.
	 *
	 * The meal builder JS finds every element with [data-fn-macros] on the page
	 * and updates them with kcal / protein / carbs / fat as the selection changes.
	 *
	 * Gated on the current product being a meal product (same gate as
	 * [fn_meal_builder]) — otherwise the "Pick your ingredients…" placeholder
	 * would print on every shared product-page template even when there's no
	 * builder for the customer to interact with. Accepts an optional
	 * product_id attribute for rendering outside the product loop.
	 */
	public function macros_shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'layout'     => 'inline', // 'inline' | 'stacked'
				'label'      => __( 'Macros', 'fastnutrition-mealprep' ),
				'empty'      => __( 'Pick your ingredients to see macros…', 'fastnutrition-mealprep' ),
				'product_id' => 0,
			],
			is_array( $atts ) ? $atts : [],
			'fn_macros'
		);
		$product_id = (int) $atts['product_id'];
		if ( ! $product_id && function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();
		}
		if ( ! $product_id || ! self::is_configurable( $product_id ) ) {
			return '';
		}
		$class = 'fn-macro-display fn-macro-' . ( $atts['layout'] === 'stacked' ? 'stacked' : 'inline' );
		return sprintf(
			'<div class="%1$s" data-fn-macros data-fn-macros-empty="%2$s" data-fn-macros-label="%3$s"><span class="fn-macro-empty">%2$s</span></div>',
			esc_attr( $class ),
			esc_attr( $atts['empty'] ),
			esc_attr( $atts['label'] )
		);
	}

	public function apply_placement(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product_id = (int) get_queried_object_id();
		if ( ! self::is_configurable( $product_id ) ) {
			return;
		}

		$placements = SettingsPage::placements();
		$key        = SettingsPage::get_placement();
		$cb         = function () use ( $product_id ): void {
			echo self::render_mount( $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};

		switch ( $key ) {
			case 'replace_add_to_cart':
				// Render the builder where the WC add-to-cart template normally goes.
				// The native button stays — the meal builder JS disables it until
				// a valid selection has been made and intercepts the submit.
				add_action( 'woocommerce_single_product_summary', $cb, 29 );
				break;
			case 'shortcode':
				// Render only where [fn_meal_builder] is placed.
				break;
			default:
				$row = $placements[ $key ] ?? null;
				if ( $row && $row['hook'] ) {
					add_action( $row['hook'], $cb, (int) $row['priority'] );
				}
		}
	}

	public function shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'product_id' => 0,
			],
			is_array( $atts ) ? $atts : [],
			'fn_meal_builder'
		);
		$product_id = (int) $atts['product_id'];
		if ( ! $product_id && function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();
		}
		if ( ! $product_id || ! self::is_configurable( $product_id ) ) {
			return '';
		}
		return self::render_mount( $product_id );
	}

	public static function render_mount( int $product_id ): string {
		wp_enqueue_script( 'fn-meal-builder' );
		wp_enqueue_style( 'fn-meal-builder' );
		return sprintf(
			'<div class="fn-meal-builder-mount" data-fn-meal-builder="1" data-product-id="%d"></div>',
			(int) $product_id
		);
	}

	public function add_product_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = [
			'label'    => __( 'Meal Builder', 'fastnutrition-mealprep' ),
			'target'   => 'fn_meal_builder_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 65,
		];
		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( 'fn_save_meal_product', 'fn_meal_product_nonce' );

		$is_meal               = (bool) get_post_meta( $post->ID, '_fn_is_meal', true );
		$tier                  = get_post_meta( $post->ID, '_fn_meal_tier', true ) ?: 'standard';
		$allow_double_greens   = (bool) get_post_meta( $post->ID, '_fn_allow_double_greens', true );
		$allow_set_meal_mode   = (bool) get_post_meta( $post->ID, '_fn_allow_set_meal_mode', true );
		$allowed_proteins      = (array) get_post_meta( $post->ID, '_fn_allowed_protein_ids', true );
		$allowed_carbs         = (array) get_post_meta( $post->ID, '_fn_allowed_carb_ids', true );
		$allowed_greens        = (array) get_post_meta( $post->ID, '_fn_allowed_greens_ids', true );
		$allowed_set_meals     = (array) get_post_meta( $post->ID, '_fn_allowed_set_meal_ids', true );

		echo '<div id="fn_meal_builder_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group" style="padding:10px 14px">';
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:4px 0 10px">';
		echo '<strong>' . esc_html__( 'Meal Builder — what this does', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Enabling this turns the product page\'s Add-to-Cart into an interactive builder: Protein + Carb + Greens (or 2 Greens instead of a Carb), or a pre-made Set Meal. The macros are summed live from the ingredients you\'ve set up under Meal Prep → Ingredients. If no ingredients are listed under "Allowed X", the builder shows every active one of that type.', 'fastnutrition-mealprep' );
		echo '</div>';
		echo '</div>';
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_is_meal',
				'label'       => __( 'Enable meal builder', 'fastnutrition-mealprep' ),
				'description' => __( 'Customers can build this product by choosing ingredients.', 'fastnutrition-mealprep' ),
				'value'       => $is_meal ? 'yes' : 'no',
			]
		);

		woocommerce_wp_select(
			[
				'id'      => '_fn_meal_tier',
				'label'   => __( 'Meal tier', 'fastnutrition-mealprep' ),
				'options' => [
					'standard' => __( 'Standard', 'fastnutrition-mealprep' ),
					'bulk'     => __( 'Bulk', 'fastnutrition-mealprep' ),
				],
				'value'   => $tier,
			]
		);

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_allow_double_greens',
				'label'       => __( 'Allow double greens', 'fastnutrition-mealprep' ),
				'description' => __( 'Let customers swap the carb for a second greens.', 'fastnutrition-mealprep' ),
				'value'       => $allow_double_greens ? 'yes' : 'no',
			]
		);

		woocommerce_wp_checkbox(
			[
				'id'          => '_fn_allow_set_meal_mode',
				'label'       => __( 'Allow set meal mode', 'fastnutrition-mealprep' ),
				'description' => __( 'Let customers choose a pre-made set meal instead of building.', 'fastnutrition-mealprep' ),
				'value'       => $allow_set_meal_mode ? 'yes' : 'no',
			]
		);

		echo '</div><div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Allowed ingredients (leave blank for all active of that type)', 'fastnutrition-mealprep' ) . '</strong></p>';
		echo '<p class="form-field"><em>' . esc_html__( 'The lists below are filtered by the Meal tier above — switching between Standard and Bulk hides ingredients that don\'t match the tier.', 'fastnutrition-mealprep' ) . '</em></p>';

		$this->render_ingredient_multiselect( 'proteins', '_fn_allowed_protein_ids', __( 'Allowed proteins', 'fastnutrition-mealprep' ), $allowed_proteins, 'protein' );
		$this->render_ingredient_multiselect( 'carbs', '_fn_allowed_carb_ids', __( 'Allowed carbs', 'fastnutrition-mealprep' ), $allowed_carbs, 'carb' );
		$this->render_ingredient_multiselect( 'greens', '_fn_allowed_greens_ids', __( 'Allowed greens', 'fastnutrition-mealprep' ), $allowed_greens, 'greens' );
		$this->render_ingredient_multiselect( 'set_meals', '_fn_allowed_set_meal_ids', __( 'Allowed set meals', 'fastnutrition-mealprep' ), $allowed_set_meals, 'set_meal' );

		echo '</div></div>';
		$this->render_tier_filter_script();
	}

	private function render_tier_filter_script(): void {
		?>
		<script>
		(function(){
			const tierField = document.getElementById('_fn_meal_tier');
			if (!tierField) return;

			const apply = () => {
				const tier = tierField.value || 'standard';
				document.querySelectorAll('select[data-fn-ingredient-list]').forEach((sel) => {
					Array.from(sel.options).forEach((opt) => {
						const optTier = opt.getAttribute('data-tier') || '';
						if (!optTier) {
							opt.hidden = false;
							return;
						}
						const matches = optTier === tier;
						opt.hidden = !matches;
						if (!matches) {
							opt.selected = false;
						}
					});
				});
			};
			tierField.addEventListener('change', apply);
			apply();
		})();
		</script>
		<?php
	}

	private function render_ingredient_multiselect( string $handle, string $key, string $label, array $selected, string $type_slug ): void {
		$ingredients = $this->get_ingredients_by_type( $type_slug );
		printf( '<p class="form-field"><label for="fn_select_%1$s">%2$s</label>', esc_attr( $handle ), esc_html( $label ) );
		printf( '<select multiple data-fn-ingredient-list="1" id="fn_select_%1$s" name="%2$s[]" style="width:50%%;min-height:100px;">', esc_attr( $handle ), esc_attr( $key ) );
		foreach ( $ingredients as $ingredient ) {
			$is_selected   = in_array( $ingredient->ID, array_map( 'intval', $selected ), true );
			$ingr_tier     = (string) get_post_meta( (int) $ingredient->ID, '_fn_tier', true );
			$ingr_tier     = $ingr_tier === 'bulk' ? 'bulk' : 'standard';
			printf(
				'<option value="%1$d" data-tier="%2$s" %3$s>%4$s</option>',
				(int) $ingredient->ID,
				esc_attr( $ingr_tier ),
				selected( $is_selected, true, false ),
				esc_html( $ingredient->post_title )
			);
		}
		echo '</select></p>';
	}

	private function get_ingredients_by_type( string $type_slug ): array {
		return get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [
					[
						'taxonomy' => IngredientType::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $type_slug,
					],
				],
			]
		);
	}

	public function save( int $product_id ): void {
		if ( ! isset( $_POST['fn_meal_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_meal_product_nonce'] ) ), 'fn_save_meal_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		update_post_meta( $product_id, '_fn_is_meal', ! empty( $_POST['_fn_is_meal'] ) );
		update_post_meta( $product_id, '_fn_allow_double_greens', ! empty( $_POST['_fn_allow_double_greens'] ) );
		update_post_meta( $product_id, '_fn_allow_set_meal_mode', ! empty( $_POST['_fn_allow_set_meal_mode'] ) );

		$tier = isset( $_POST['_fn_meal_tier'] ) ? sanitize_key( wp_unslash( $_POST['_fn_meal_tier'] ) ) : 'standard';
		update_post_meta( $product_id, '_fn_meal_tier', in_array( $tier, [ 'standard', 'bulk' ], true ) ? $tier : 'standard' );

		foreach ( [ '_fn_allowed_protein_ids', '_fn_allowed_carb_ids', '_fn_allowed_greens_ids', '_fn_allowed_set_meal_ids' ] as $key ) {
			$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] )
				? array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) )
				: [];
			update_post_meta( $product_id, $key, array_values( $values ) );
		}
	}

	public static function is_meal( int $product_id ): bool {
		return (bool) get_post_meta( $product_id, '_fn_is_meal', true );
	}

	/**
	 * True when the product page should render the interactive configurator —
	 * either the meal builder or a standalone product picker. Used as the gate
	 * for placement, the cart-selection pipeline and the offline order builder.
	 */
	public static function is_configurable( int $product_id ): bool {
		return self::is_meal( $product_id ) || StandaloneProduct::is_enabled( $product_id );
	}

	public static function get_config( int $product_id ): array {
		return [
			'is_meal'              => self::is_meal( $product_id ),
			'tier'                 => get_post_meta( $product_id, '_fn_meal_tier', true ) ?: 'standard',
			'allow_double_greens'  => (bool) get_post_meta( $product_id, '_fn_allow_double_greens', true ),
			'allow_set_meal_mode'  => (bool) get_post_meta( $product_id, '_fn_allow_set_meal_mode', true ),
			'allowed_proteins'     => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_protein_ids', true ) ),
			'allowed_carbs'        => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_carb_ids', true ) ),
			'allowed_greens'       => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_greens_ids', true ) ),
			'allowed_set_meals'    => array_map( 'intval', (array) get_post_meta( $product_id, '_fn_allowed_set_meal_ids', true ) ),
			'standalone'           => StandaloneProduct::get_config( $product_id ),
		];
	}
}

<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Post;

/**
 * "Standalone Product" — sells one or more pre-made items (a Set Meal or a
 * Sweet) directly, instead of building a meal from components.
 *
 * Unlike the Meal Builder, a standalone product is bound to a single
 * IngredientType (Set Meal OR Sweet) and an allow-list of items of that type:
 *   - exactly one item  → no customer choice; it is auto-selected.
 *   - two or more items → the customer picks one from a dropdown on the page.
 *
 * The resulting cart/order selection uses the generic 'standalone' mode
 * (see Cart\Selection), so it flows through pricing, macros, the prep sheet,
 * labels, the Quick Order screen and the Quick Label Maker exactly like any
 * other meal selection.
 */
final class StandaloneProduct {

	public const TAB_ID = 'fn_standalone';

	public const META_ENABLED      = '_fn_standalone_enabled';
	public const META_TYPE         = '_fn_standalone_type';
	public const META_SET_MEAL_IDS = '_fn_standalone_set_meal_ids';
	public const META_SWEET_IDS    = '_fn_standalone_sweet_ids';

	/** IngredientType slugs a standalone product may draw from. */
	public const ALLOWED_TYPES = [ 'set_meal', 'sweet' ];

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
	}

	public function add_product_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = [
			'label'    => __( 'Standalone Product', 'fastnutrition-mealprep' ),
			'target'   => 'fn_standalone_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 66,
		];
		return $tabs;
	}

	public function render_product_panel(): void {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( 'fn_save_standalone_product', 'fn_standalone_nonce' );

		$enabled       = (bool) get_post_meta( $post->ID, self::META_ENABLED, true );
		$type          = (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$type          = in_array( $type, self::ALLOWED_TYPES, true ) ? $type : 'set_meal';
		$set_meal_ids  = array_map( 'intval', (array) get_post_meta( $post->ID, self::META_SET_MEAL_IDS, true ) );
		$sweet_ids     = array_map( 'intval', (array) get_post_meta( $post->ID, self::META_SWEET_IDS, true ) );

		echo '<div id="fn_standalone_panel" class="panel woocommerce_options_panel">';
		echo '<div class="options_group" style="padding:10px 14px">';
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:4px 0 10px">';
		echo '<strong>' . esc_html__( 'Standalone Product — what this does', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Sells a pre-made item directly (a Set Meal or a Sweet) instead of building a meal. Choose the type, then tick one or more items. If you tick exactly one, the customer just adds it to the basket. If you tick more than one, the product page shows a dropdown so the customer can choose which. Macros, prep sheets, labels and the Quick Order tools all work from your choice automatically.', 'fastnutrition-mealprep' );
		echo '</div>';
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			[
				'id'          => self::META_ENABLED,
				'label'       => __( 'Enable standalone product', 'fastnutrition-mealprep' ),
				'description' => __( 'Sell the item(s) below directly on this product page.', 'fastnutrition-mealprep' ),
				'value'       => $enabled ? 'yes' : 'no',
			]
		);

		woocommerce_wp_select(
			[
				'id'      => self::META_TYPE,
				'label'   => __( 'Item type', 'fastnutrition-mealprep' ),
				'options' => [
					'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
					'sweet'    => __( 'Sweet', 'fastnutrition-mealprep' ),
				],
				'value'   => $type,
			]
		);
		echo '</div>';

		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Items to offer', 'fastnutrition-mealprep' ) . '</strong></p>';
		echo '<p class="form-field"><em>' . esc_html__( 'Pick one item for a fixed product, or several to give the customer a dropdown to choose from. The list matches the Item type selected above.', 'fastnutrition-mealprep' ) . '</em></p>';

		$this->render_items_multiselect( 'set_meal', self::META_SET_MEAL_IDS, __( 'Set meals to offer', 'fastnutrition-mealprep' ), $set_meal_ids, 'set_meal' === $type );
		$this->render_items_multiselect( 'sweet', self::META_SWEET_IDS, __( 'Sweets to offer', 'fastnutrition-mealprep' ), $sweet_ids, 'sweet' === $type );

		echo '</div></div>';
		$this->render_type_toggle_script();
	}

	private function render_items_multiselect( string $type_slug, string $key, string $label, array $selected, bool $visible ): void {
		$ingredients = $this->get_ingredients_by_type( $type_slug );
		printf(
			'<p class="form-field fn-standalone-items" data-fn-standalone-for="%1$s" style="%2$s">',
			esc_attr( $type_slug ),
			$visible ? '' : 'display:none'
		);
		printf( '<label for="fn_standalone_%1$s">%2$s</label>', esc_attr( $type_slug ), esc_html( $label ) );
		printf( '<select multiple id="fn_standalone_%1$s" name="%2$s[]" style="width:50%%;min-height:100px;">', esc_attr( $type_slug ), esc_attr( $key ) );
		foreach ( $ingredients as $ingredient ) {
			$is_selected = in_array( (int) $ingredient->ID, $selected, true );
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $ingredient->ID,
				selected( $is_selected, true, false ),
				esc_html( $ingredient->post_title )
			);
		}
		echo '</select></p>';
	}

	private function render_type_toggle_script(): void {
		?>
		<script>
		(function(){
			const typeField = document.getElementById('<?php echo esc_js( self::META_TYPE ); ?>');
			if (!typeField) return;
			const apply = () => {
				const type = typeField.value || 'set_meal';
				document.querySelectorAll('.fn-standalone-items').forEach((row) => {
					row.style.display = row.getAttribute('data-fn-standalone-for') === type ? '' : 'none';
				});
			};
			typeField.addEventListener('change', apply);
			apply();
		})();
		</script>
		<?php
	}

	private function get_ingredients_by_type( string $type_slug ): array {
		return get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
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
		if ( ! isset( $_POST['fn_standalone_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_standalone_nonce'] ) ), 'fn_save_standalone_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		update_post_meta( $product_id, self::META_ENABLED, ! empty( $_POST[ self::META_ENABLED ] ) );

		$type = isset( $_POST[ self::META_TYPE ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_TYPE ] ) ) : 'set_meal';
		update_post_meta( $product_id, self::META_TYPE, in_array( $type, self::ALLOWED_TYPES, true ) ? $type : 'set_meal' );

		foreach ( [ self::META_SET_MEAL_IDS, self::META_SWEET_IDS ] as $key ) {
			$values = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] )
				? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) ) )
				: [];
			update_post_meta( $product_id, $key, $values );
		}
	}

	public static function is_enabled( int $product_id ): bool {
		return (bool) get_post_meta( $product_id, self::META_ENABLED, true );
	}

	/**
	 * @return array{enabled:bool,type:string,ids:int[]}
	 */
	public static function get_config( int $product_id ): array {
		$type = (string) get_post_meta( $product_id, self::META_TYPE, true );
		$type = in_array( $type, self::ALLOWED_TYPES, true ) ? $type : 'set_meal';
		$ids  = 'sweet' === $type
			? (array) get_post_meta( $product_id, self::META_SWEET_IDS, true )
			: (array) get_post_meta( $product_id, self::META_SET_MEAL_IDS, true );

		return [
			'enabled' => self::is_enabled( $product_id ),
			'type'    => $type,
			'ids'     => array_values( array_filter( array_map( 'intval', $ids ) ) ),
		];
	}
}

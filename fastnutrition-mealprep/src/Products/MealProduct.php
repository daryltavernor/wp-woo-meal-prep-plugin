<?php
/**
 * "Meal Builder" product meta box on simple WooCommerce products.
 * Stores which ingredient IDs are allowed and which modes (set-meal, double greens) are enabled.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class MealProduct {

	public const META_IS_MEAL          = '_fn_is_meal';
	public const META_TIER             = '_fn_meal_tier';
	public const META_ALLOW_DOUBLE     = '_fn_allow_double_greens';
	public const META_ALLOW_SET_MEAL   = '_fn_allow_set_meal_mode';
	public const META_ALLOWED_PROTEIN  = '_fn_allowed_protein_ids';
	public const META_ALLOWED_CARB     = '_fn_allowed_carb_ids';
	public const META_ALLOWED_GREENS   = '_fn_allowed_greens_ids';
	public const META_ALLOWED_SET      = '_fn_allowed_set_meal_ids';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function tab( array $tabs ): array {
		$tabs['fn_meal'] = [
			'label'    => __( 'Meal Builder', 'fastnutrition-mealprep' ),
			'target'   => 'fn_meal_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 21,
		];
		return $tabs;
	}

	public function panel(): void {
		global $post;
		$product_id = (int) $post->ID;

		$is_meal         = 'yes' === get_post_meta( $product_id, self::META_IS_MEAL, true );
		$tier            = get_post_meta( $product_id, self::META_TIER, true ) ?: Ingredient::TIER_STANDARD;
		$allow_double    = 'yes' === get_post_meta( $product_id, self::META_ALLOW_DOUBLE, true );
		$allow_set_meal  = 'yes' === get_post_meta( $product_id, self::META_ALLOW_SET_MEAL, true );
		$allowed_protein = (array) get_post_meta( $product_id, self::META_ALLOWED_PROTEIN, true );
		$allowed_carb    = (array) get_post_meta( $product_id, self::META_ALLOWED_CARB, true );
		$allowed_greens  = (array) get_post_meta( $product_id, self::META_ALLOWED_GREENS, true );
		$allowed_set     = (array) get_post_meta( $product_id, self::META_ALLOWED_SET, true );

		$ingredients_by_type = [
			IngredientType::TERM_PROTEIN  => $this->fetch_ingredients( IngredientType::TERM_PROTEIN ),
			IngredientType::TERM_CARB     => $this->fetch_ingredients( IngredientType::TERM_CARB ),
			IngredientType::TERM_GREENS   => $this->fetch_ingredients( IngredientType::TERM_GREENS ),
			IngredientType::TERM_SET_MEAL => $this->fetch_ingredients( IngredientType::TERM_SET_MEAL ),
		];

		?>
		<div id="fn_meal_panel" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<label for="fn_is_meal"><?php esc_html_e( 'Is a meal?', 'fastnutrition-mealprep' ); ?></label>
					<input type="checkbox" id="fn_is_meal" name="fn_is_meal" value="yes" <?php checked( $is_meal ); ?>>
					<span class="description"><?php esc_html_e( 'Enable the builder front-end for this product.', 'fastnutrition-mealprep' ); ?></span>
				</p>
				<p class="form-field">
					<label for="fn_meal_tier"><?php esc_html_e( 'Meal tier', 'fastnutrition-mealprep' ); ?></label>
					<select id="fn_meal_tier" name="fn_meal_tier">
						<option value="standard" <?php selected( $tier, 'standard' ); ?>><?php esc_html_e( 'Standard', 'fastnutrition-mealprep' ); ?></option>
						<option value="bulk" <?php selected( $tier, 'bulk' ); ?>><?php esc_html_e( 'Bulk', 'fastnutrition-mealprep' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label for="fn_allow_double_greens"><?php esc_html_e( 'Allow double greens', 'fastnutrition-mealprep' ); ?></label>
					<input type="checkbox" id="fn_allow_double_greens" name="fn_allow_double_greens" value="yes" <?php checked( $allow_double ); ?>>
					<span class="description"><?php esc_html_e( 'Lets customers swap the carb for a second greens.', 'fastnutrition-mealprep' ); ?></span>
				</p>
				<p class="form-field">
					<label for="fn_allow_set_meal_mode"><?php esc_html_e( 'Allow set-meal mode', 'fastnutrition-mealprep' ); ?></label>
					<input type="checkbox" id="fn_allow_set_meal_mode" name="fn_allow_set_meal_mode" value="yes" <?php checked( $allow_set_meal ); ?>>
					<span class="description"><?php esc_html_e( 'Adds a toggle letting customers pick from set meals instead of building their own.', 'fastnutrition-mealprep' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<h4 style="padding: 0 12px;"><?php esc_html_e( 'Allowed ingredients', 'fastnutrition-mealprep' ); ?></h4>
				<p class="description" style="padding: 0 12px;"><?php esc_html_e( 'Leave a group empty to allow all active ingredients of that type.', 'fastnutrition-mealprep' ); ?></p>
				<?php
				$this->render_ingredient_picker( 'fn_allowed_protein_ids', __( 'Proteins', 'fastnutrition-mealprep' ), $ingredients_by_type[ IngredientType::TERM_PROTEIN ], $allowed_protein );
				$this->render_ingredient_picker( 'fn_allowed_carb_ids', __( 'Carbs', 'fastnutrition-mealprep' ), $ingredients_by_type[ IngredientType::TERM_CARB ], $allowed_carb );
				$this->render_ingredient_picker( 'fn_allowed_greens_ids', __( 'Greens', 'fastnutrition-mealprep' ), $ingredients_by_type[ IngredientType::TERM_GREENS ], $allowed_greens );
				$this->render_ingredient_picker( 'fn_allowed_set_meal_ids', __( 'Set Meals', 'fastnutrition-mealprep' ), $ingredients_by_type[ IngredientType::TERM_SET_MEAL ], $allowed_set );
				?>
			</div>
		</div>
		<?php
	}

	private function render_ingredient_picker( string $name, string $label, array $options, array $selected ): void {
		?>
		<p class="form-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>[]" multiple style="min-width: 280px; min-height: 120px;">
				<?php foreach ( $options as $id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( in_array( $id, $selected, true ), true ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * @return array<int,string> id => title
	 */
	private function fetch_ingredients( string $type_slug ): array {
		$posts = get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [
					[
						'taxonomy' => IngredientType::SLUG,
						'field'    => 'slug',
						'terms'    => $type_slug,
					],
				],
				'meta_query'     => [
					[ 'key' => Ingredient::META_ACTIVE, 'value' => '1', 'compare' => '=' ],
				],
			]
		);
		$out = [];
		foreach ( $posts as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function save( \WC_Product $product ): void {
		$product_id = $product->get_id();

		update_post_meta( $product_id, self::META_IS_MEAL, ! empty( $_POST['fn_is_meal'] ) ? 'yes' : 'no' );

		$tier = isset( $_POST['fn_meal_tier'] ) && 'bulk' === $_POST['fn_meal_tier'] ? 'bulk' : 'standard';
		update_post_meta( $product_id, self::META_TIER, $tier );

		update_post_meta( $product_id, self::META_ALLOW_DOUBLE, ! empty( $_POST['fn_allow_double_greens'] ) ? 'yes' : 'no' );
		update_post_meta( $product_id, self::META_ALLOW_SET_MEAL, ! empty( $_POST['fn_allow_set_meal_mode'] ) ? 'yes' : 'no' );

		foreach (
			[
				'fn_allowed_protein_ids'  => self::META_ALLOWED_PROTEIN,
				'fn_allowed_carb_ids'     => self::META_ALLOWED_CARB,
				'fn_allowed_greens_ids'   => self::META_ALLOWED_GREENS,
				'fn_allowed_set_meal_ids' => self::META_ALLOWED_SET,
			] as $field => $meta_key
		) {
			$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? array_map( 'absint', wp_unslash( $_POST[ $field ] ) ) : [];
			update_post_meta( $product_id, $meta_key, array_values( array_filter( $raw ) ) );
		}
	}

	public static function is_meal( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::META_IS_MEAL, true );
	}

	public static function config( int $product_id ): array {
		return [
			'is_meal'          => self::is_meal( $product_id ),
			'tier'             => get_post_meta( $product_id, self::META_TIER, true ) ?: Ingredient::TIER_STANDARD,
			'allow_double'     => 'yes' === get_post_meta( $product_id, self::META_ALLOW_DOUBLE, true ),
			'allow_set_meal'   => 'yes' === get_post_meta( $product_id, self::META_ALLOW_SET_MEAL, true ),
			'allowed_protein'  => array_map( 'intval', (array) get_post_meta( $product_id, self::META_ALLOWED_PROTEIN, true ) ),
			'allowed_carb'     => array_map( 'intval', (array) get_post_meta( $product_id, self::META_ALLOWED_CARB, true ) ),
			'allowed_greens'   => array_map( 'intval', (array) get_post_meta( $product_id, self::META_ALLOWED_GREENS, true ) ),
			'allowed_set_meal' => array_map( 'intval', (array) get_post_meta( $product_id, self::META_ALLOWED_SET, true ) ),
		];
	}
}

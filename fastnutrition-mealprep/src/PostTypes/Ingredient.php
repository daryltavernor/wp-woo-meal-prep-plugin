<?php
/**
 * 'fn_ingredient' CPT — protein, carb, greens, set meal.
 * Stores macros, price delta, tier, allergens.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\PostTypes;

use FastNutrition\MealPrep\Support\Security;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class Ingredient {

	public const POST_TYPE  = 'fn_ingredient';
	public const META_TYPE  = '_fn_type';
	public const META_TIER  = '_fn_tier';
	public const META_MACROS = '_fn_macros';
	public const META_PRICE = '_fn_price_delta';
	public const META_ACTIVE = '_fn_active';

	public const TIER_STANDARD = 'standard';
	public const TIER_BULK     = 'bulk';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ], 6 );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'rest_api_init', [ $this, 'register_meta' ] );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => __( 'Ingredients', 'fastnutrition-mealprep' ),
					'singular_name'      => __( 'Ingredient', 'fastnutrition-mealprep' ),
					'add_new_item'       => __( 'Add New Ingredient', 'fastnutrition-mealprep' ),
					'edit_item'          => __( 'Edit Ingredient', 'fastnutrition-mealprep' ),
					'new_item'           => __( 'New Ingredient', 'fastnutrition-mealprep' ),
					'view_item'          => __( 'View Ingredient', 'fastnutrition-mealprep' ),
					'search_items'       => __( 'Search Ingredients', 'fastnutrition-mealprep' ),
					'not_found'          => __( 'No ingredients found.', 'fastnutrition-mealprep' ),
					'menu_name'          => __( 'Ingredients', 'fastnutrition-mealprep' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=fn_ingredient',
				'show_in_rest'        => true,
				'rest_base'           => 'fn-ingredients',
				'supports'            => [ 'title', 'thumbnail' ],
				'has_archive'         => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'menu_icon'           => 'dashicons-carrot',
				'taxonomies'          => [ IngredientType::SLUG, Allergen::SLUG ],
			]
		);
	}

	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_MACROS,
			[
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => [
					'schema' => [
						'type'       => 'object',
						'properties' => [
							'kcal'      => [ 'type' => 'number' ],
							'protein_g' => [ 'type' => 'number' ],
							'carbs_g'   => [ 'type' => 'number' ],
							'fat_g'     => [ 'type' => 'number' ],
							'fibre_g'   => [ 'type' => 'number' ],
						],
					],
				],
				'auth_callback' => [ Security::class, 'can_manage' ],
			]
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_TIER,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => self::TIER_STANDARD,
				'auth_callback' => [ Security::class, 'can_manage' ],
			]
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_PRICE,
			[
				'type'          => 'number',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => 0,
				'auth_callback' => [ Security::class, 'can_manage' ],
			]
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_ACTIVE,
			[
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => true,
				'auth_callback' => [ Security::class, 'can_manage' ],
			]
		);
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'fn_ingredient_details',
			__( 'Ingredient Details', 'fastnutrition-mealprep' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		$macros = get_post_meta( $post->ID, self::META_MACROS, true );
		$macros = is_array( $macros ) ? $macros : [];
		$tier   = get_post_meta( $post->ID, self::META_TIER, true ) ?: self::TIER_STANDARD;
		$price  = (float) get_post_meta( $post->ID, self::META_PRICE, true );
		$active = (bool) get_post_meta( $post->ID, self::META_ACTIVE, true );

		wp_nonce_field( 'fn_ingredient_save', '_fn_ingredient_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label for="fn_tier"><?php esc_html_e( 'Tier', 'fastnutrition-mealprep' ); ?></label></th>
				<td>
					<select id="fn_tier" name="fn_tier">
						<option value="standard" <?php selected( $tier, 'standard' ); ?>><?php esc_html_e( 'Standard', 'fastnutrition-mealprep' ); ?></option>
						<option value="bulk" <?php selected( $tier, 'bulk' ); ?>><?php esc_html_e( 'Bulk', 'fastnutrition-mealprep' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Used for set-meal matching. For component ingredients (protein/carb/greens) the tier comes from the meal product.', 'fastnutrition-mealprep' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Macros per portion', 'fastnutrition-mealprep' ); ?></th>
				<td>
					<label>kcal <input type="number" step="0.1" min="0" name="fn_macros[kcal]" value="<?php echo esc_attr( (string) ( $macros['kcal'] ?? '' ) ); ?>"></label>
					<label>Protein (g) <input type="number" step="0.1" min="0" name="fn_macros[protein_g]" value="<?php echo esc_attr( (string) ( $macros['protein_g'] ?? '' ) ); ?>"></label>
					<label>Carbs (g) <input type="number" step="0.1" min="0" name="fn_macros[carbs_g]" value="<?php echo esc_attr( (string) ( $macros['carbs_g'] ?? '' ) ); ?>"></label>
					<label>Fat (g) <input type="number" step="0.1" min="0" name="fn_macros[fat_g]" value="<?php echo esc_attr( (string) ( $macros['fat_g'] ?? '' ) ); ?>"></label>
					<label>Fibre (g) <input type="number" step="0.1" min="0" name="fn_macros[fibre_g]" value="<?php echo esc_attr( (string) ( $macros['fibre_g'] ?? '' ) ); ?>"></label>
				</td>
			</tr>
			<tr>
				<th><label for="fn_price_delta"><?php esc_html_e( 'Price modifier (£)', 'fastnutrition-mealprep' ); ?></label></th>
				<td>
					<input type="number" step="0.01" id="fn_price_delta" name="fn_price_delta" value="<?php echo esc_attr( (string) $price ); ?>">
					<p class="description"><?php esc_html_e( 'Optional surcharge when this ingredient is selected. Usually 0.', 'fastnutrition-mealprep' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'fastnutrition-mealprep' ); ?></th>
				<td>
					<label><input type="checkbox" name="fn_active" value="1" <?php checked( $active ); ?>> <?php esc_html_e( 'Active (available in the builder and macro calculator)', 'fastnutrition-mealprep' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_meta( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['_fn_ingredient_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_fn_ingredient_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'fn_ingredient_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$tier = isset( $_POST['fn_tier'] ) && 'bulk' === $_POST['fn_tier'] ? self::TIER_BULK : self::TIER_STANDARD;
		update_post_meta( $post_id, self::META_TIER, $tier );

		$macros_raw = isset( $_POST['fn_macros'] ) && is_array( $_POST['fn_macros'] ) ? wp_unslash( $_POST['fn_macros'] ) : [];
		$macros = [];
		foreach ( [ 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g' ] as $key ) {
			$macros[ $key ] = isset( $macros_raw[ $key ] ) ? (float) $macros_raw[ $key ] : 0.0;
		}
		update_post_meta( $post_id, self::META_MACROS, $macros );

		update_post_meta( $post_id, self::META_PRICE, isset( $_POST['fn_price_delta'] ) ? (float) $_POST['fn_price_delta'] : 0.0 );
		update_post_meta( $post_id, self::META_ACTIVE, ! empty( $_POST['fn_active'] ) );
	}

	public function columns( array $cols ): array {
		$new = [];
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['fn_type']   = __( 'Type', 'fastnutrition-mealprep' );
				$new['fn_tier']   = __( 'Tier', 'fastnutrition-mealprep' );
				$new['fn_macros'] = __( 'Macros', 'fastnutrition-mealprep' );
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'fn_type' === $column ) {
			$terms = wp_get_post_terms( $post_id, IngredientType::SLUG, [ 'fields' => 'names' ] );
			echo esc_html( implode( ', ', (array) $terms ) );
		}
		if ( 'fn_tier' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_TIER, true ) );
		}
		if ( 'fn_macros' === $column ) {
			$m = get_post_meta( $post_id, self::META_MACROS, true );
			if ( is_array( $m ) ) {
				echo esc_html( sprintf( '%.0f kcal · P %.0f · C %.0f · F %.0f', $m['kcal'] ?? 0, $m['protein_g'] ?? 0, $m['carbs_g'] ?? 0, $m['fat_g'] ?? 0 ) );
			}
		}
	}

	/**
	 * Fetch an ingredient's macros + metadata for use in cart/pricing.
	 */
	public static function get( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$macros = get_post_meta( $post_id, self::META_MACROS, true );
		$types  = wp_get_post_terms( $post_id, IngredientType::SLUG, [ 'fields' => 'slugs' ] );
		return [
			'id'           => $post_id,
			'title'        => $post->post_title,
			'type'         => $types[0] ?? '',
			'tier'         => get_post_meta( $post_id, self::META_TIER, true ) ?: self::TIER_STANDARD,
			'price_delta'  => (float) get_post_meta( $post_id, self::META_PRICE, true ),
			'macros'       => is_array( $macros ) ? $macros : [],
			'active'       => (bool) get_post_meta( $post_id, self::META_ACTIVE, true ),
			'allergens'    => wp_get_post_terms( $post_id, Allergen::SLUG, [ 'fields' => 'slugs' ] ),
		];
	}
}

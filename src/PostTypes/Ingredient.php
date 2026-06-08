<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\PostTypes;

use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WP_Post;

final class Ingredient {

	public const POST_TYPE = 'fn_ingredient';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'list_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'list_column_content' ], 10, 2 );
	}

	public function list_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['fn_type']   = __( 'Type', 'fastnutrition-mealprep' );
				$new['fn_macros'] = __( 'Macros (kcal · P/C/F)', 'fastnutrition-mealprep' );
			}
		}
		return $new;
	}

	public function list_column_content( string $column, int $post_id ): void {
		if ( 'fn_type' === $column ) {
			$slug  = self::get_type_slug( $post_id );
			$map   = [
				'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
				'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
				'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
				'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
				'sweet'    => __( 'Sweet', 'fastnutrition-mealprep' ),
			];
			$label = $map[ $slug ] ?? '—';
			$color = [
				'protein'  => '#b34b00',
				'carb'     => '#2271b1',
				'greens'   => '#006400',
				'set_meal' => '#7c3aed',
				'sweet'    => '#d97706',
			][ $slug ] ?? '#666';
			printf( '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%s;color:#fff;font-size:11px;font-weight:600">%s</span>', esc_attr( $color ), esc_html( $label ) );
		} elseif ( 'fn_macros' === $column ) {
			$m = self::get_macros( $post_id );
			printf(
				'%s · %sg / %sg / %sg',
				esc_html( number_format( $m['kcal'], 0 ) ),
				esc_html( number_format( $m['protein_g'], 1 ) ),
				esc_html( number_format( $m['carbs_g'], 1 ) ),
				esc_html( number_format( $m['fat_g'], 1 ) )
			);
		}
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'        => __( 'Ingredients', 'fastnutrition-mealprep' ),
				'labels'       => [
					'name'          => __( 'Ingredients & Set Meals', 'fastnutrition-mealprep' ),
					'singular_name' => __( 'Ingredient', 'fastnutrition-mealprep' ),
					'add_new'       => __( 'Add Ingredient', 'fastnutrition-mealprep' ),
					'add_new_item'  => __( 'Add New Ingredient', 'fastnutrition-mealprep' ),
					'edit_item'     => __( 'Edit Ingredient', 'fastnutrition-mealprep' ),
					'menu_name'     => __( 'Ingredients', 'fastnutrition-mealprep' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'fn-mealprep',
				'show_in_rest' => true,
				'supports'     => [ 'title', 'thumbnail', 'excerpt' ],
				'taxonomies'   => [ IngredientType::TAXONOMY, Allergen::TAXONOMY ],
				'capabilities' => [
					'edit_post'          => 'edit_product',
					'read_post'          => 'read_product',
					'delete_post'        => 'delete_product',
					'edit_posts'         => 'edit_products',
					'edit_others_posts'  => 'edit_others_products',
					'publish_posts'      => 'publish_products',
					'read_private_posts' => 'read_private_products',
				],
				'map_meta_cap' => true,
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_macros',
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
						],
					],
				],
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_tier',
			[
				'type'          => 'string',
				'single'        => true,
				'default'       => 'standard',
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_price_delta',
			[
				'type'          => 'number',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'_fn_active',
			[
				'type'          => 'boolean',
				'single'        => true,
				'default'       => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_products' ),
			]
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'fn_ingredient_details',
			__( 'Ingredient Details', 'fastnutrition-mealprep' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'fn_save_ingredient', 'fn_ingredient_nonce' );
		$macros      = get_post_meta( $post->ID, '_fn_macros', true );
		$macros      = is_array( $macros ) ? $macros : [];
		$tier        = get_post_meta( $post->ID, '_fn_tier', true ) ?: 'standard';
		$price_delta = get_post_meta( $post->ID, '_fn_price_delta', true ) ?: 0;
		$active      = get_post_meta( $post->ID, '_fn_active', true );
		$active      = '' === $active ? true : (bool) $active;
		$current_type = self::get_type_slug( $post->ID );

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin-bottom:12px;">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What is this for?', 'fastnutrition-mealprep' ) . '</strong> ';
		echo esc_html__( 'Ingredients are the building blocks for the meal builder. Pick the Type below: Protein, Carb, and Greens are components of a built meal. Set Meals are complete pre-made meals customers can pick instead of building from components. Customers may also pick 2 Greens instead of a Carb if the product allows "double greens".', 'fastnutrition-mealprep' );
		echo '</p></div>';

		// Prominent type selector.
		$types = [
			'protein'  => [ __( 'Protein', 'fastnutrition-mealprep' ), __( 'A single protein (chicken breast, tofu, salmon…). Exactly one is chosen per built meal.', 'fastnutrition-mealprep' ) ],
			'carb'     => [ __( 'Carb', 'fastnutrition-mealprep' ), __( 'A single carb (rice, sweet potato…). Optional — customers can swap it for a 2nd Greens if the meal allows.', 'fastnutrition-mealprep' ) ],
			'greens'   => [ __( 'Greens', 'fastnutrition-mealprep' ), __( 'A vegetable side. Customers can pick 1, or 2 different Greens instead of a carb.', 'fastnutrition-mealprep' ) ],
			'set_meal' => [ __( 'Set Meal', 'fastnutrition-mealprep' ), __( 'A complete pre-made meal. Chosen instead of building from components.', 'fastnutrition-mealprep' ) ],
			'sweet'    => [ __( 'Sweet', 'fastnutrition-mealprep' ), __( 'A dessert or sweet snack (protein flapjacks, energy balls, overnight oats…). Sold as a separate product or add-on rather than part of the meal builder.', 'fastnutrition-mealprep' ) ],
		];
		echo '<h3 style="margin:4px 0">' . esc_html__( 'Type (required)', 'fastnutrition-mealprep' ) . '</h3>';
		echo '<p class="description" style="margin-top:0">' . esc_html__( 'Pick one. This controls which dropdown the ingredient appears in on the product page.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-bottom:16px">';
		foreach ( $types as $slug => $row ) {
			$checked = checked( $current_type, $slug, false );
			$active_style = '' !== $checked ? 'background:#e6f3e6;border-color:#006400;' : '';
			printf(
				'<label style="display:block;padding:10px;border:2px solid #ccc;border-radius:6px;cursor:pointer;%s">
					<input type="radio" name="fn_ingredient_type" value="%s" %s style="margin-right:6px" />
					<strong>%s</strong>
					<br><small style="color:#555">%s</small>
				</label>',
				esc_attr( $active_style ),
				esc_attr( $slug ),
				$checked,
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}
		echo '</div>';

		$fields      = [
			'kcal'      => __( 'Calories (kcal)', 'fastnutrition-mealprep' ),
			'protein_g' => __( 'Protein (g)', 'fastnutrition-mealprep' ),
			'carbs_g'   => __( 'Carbs (g)', 'fastnutrition-mealprep' ),
			'fat_g'     => __( 'Fat (g)', 'fastnutrition-mealprep' ),
		];
		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $label ) {
			$value = isset( $macros[ $key ] ) ? (float) $macros[ $key ] : 0.0;
			printf(
				'<tr><th><label for="fn_macros_%1$s">%2$s</label></th><td><input type="number" step="0.1" min="0" id="fn_macros_%1$s" name="fn_macros[%1$s]" value="%3$s" /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) $value )
			);
		}
		printf(
			'<tr><th><label for="fn_tier">%1$s</label></th><td><select id="fn_tier" name="fn_tier"><option value="standard" %2$s>%3$s</option><option value="bulk" %4$s>%5$s</option></select></td></tr>',
			esc_html__( 'Tier', 'fastnutrition-mealprep' ),
			selected( $tier, 'standard', false ),
			esc_html__( 'Standard', 'fastnutrition-mealprep' ),
			selected( $tier, 'bulk', false ),
			esc_html__( 'Bulk', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th><label for="fn_price_delta">%1$s</label></th><td><input type="number" step="0.01" id="fn_price_delta" name="fn_price_delta" value="%2$s" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Price modifier', 'fastnutrition-mealprep' ),
			esc_attr( (string) $price_delta ),
			esc_html__( 'Added to the meal price when this ingredient is chosen. Leave 0 for no extra charge.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th>%1$s</th><td><label><input type="checkbox" name="fn_active" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Active', 'fastnutrition-mealprep' ),
			checked( $active, true, false ),
			esc_html__( 'Available for customers to select', 'fastnutrition-mealprep' )
		);
		echo '</tbody></table>';
		?>
		<script>
		(function(){
			const radios = document.querySelectorAll('input[name="fn_ingredient_type"]');
			const update = () => {
				radios.forEach((r) => {
					const lbl = r.closest('label');
					if (!lbl) return;
					if (r.checked) {
						lbl.style.background = '#e6f3e6';
						lbl.style.borderColor = '#006400';
					} else {
						lbl.style.background = '';
						lbl.style.borderColor = '#ccc';
					}
				});
			};
			radios.forEach((r) => r.addEventListener('change', update));
			update();
		})();
		</script>
		<?php
	}

	public function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['fn_ingredient_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_ingredient_nonce'] ) ), 'fn_save_ingredient' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw    = isset( $_POST['fn_macros'] ) && is_array( $_POST['fn_macros'] ) ? wp_unslash( $_POST['fn_macros'] ) : [];
		$macros = [
			'kcal'      => isset( $raw['kcal'] ) ? (float) $raw['kcal'] : 0,
			'protein_g' => isset( $raw['protein_g'] ) ? (float) $raw['protein_g'] : 0,
			'carbs_g'   => isset( $raw['carbs_g'] ) ? (float) $raw['carbs_g'] : 0,
			'fat_g'     => isset( $raw['fat_g'] ) ? (float) $raw['fat_g'] : 0,
		];
		update_post_meta( $post_id, '_fn_macros', $macros );

		$tier = isset( $_POST['fn_tier'] ) ? sanitize_key( wp_unslash( $_POST['fn_tier'] ) ) : 'standard';
		update_post_meta( $post_id, '_fn_tier', in_array( $tier, [ 'standard', 'bulk' ], true ) ? $tier : 'standard' );

		$price_delta = isset( $_POST['fn_price_delta'] ) ? (float) $_POST['fn_price_delta'] : 0;
		update_post_meta( $post_id, '_fn_price_delta', $price_delta );

		update_post_meta( $post_id, '_fn_active', ! empty( $_POST['fn_active'] ) );

		if ( isset( $_POST['fn_ingredient_type'] ) ) {
			$type_slug = sanitize_key( wp_unslash( (string) $_POST['fn_ingredient_type'] ) );
			if ( in_array( $type_slug, [ 'protein', 'carb', 'greens', 'set_meal', 'sweet' ], true ) ) {
				$term = get_term_by( 'slug', $type_slug, IngredientType::TAXONOMY );
				if ( ! $term ) {
					$labels  = [
						'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
						'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
						'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
						'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
						'sweet'    => __( 'Sweet', 'fastnutrition-mealprep' ),
					];
					$created = wp_insert_term( $labels[ $type_slug ], IngredientType::TAXONOMY, [ 'slug' => $type_slug ] );
					if ( ! is_wp_error( $created ) ) {
						$term = get_term( $created['term_id'], IngredientType::TAXONOMY );
					}
				}
				if ( $term && ! is_wp_error( $term ) ) {
					wp_set_object_terms( $post_id, [ (int) $term->term_id ], IngredientType::TAXONOMY, false );
				}
			}
		}
	}

	public function register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE,
			'fn_type_slug',
			[
				'get_callback' => static function ( array $post ): string {
					$terms = wp_get_post_terms( (int) $post['id'], IngredientType::TAXONOMY, [ 'fields' => 'slugs' ] );
					return is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
				},
				'schema'       => [ 'type' => 'string' ],
			]
		);
		register_rest_field(
			self::POST_TYPE,
			'fn_allergens',
			[
				'get_callback' => static function ( array $post ): array {
					$terms = wp_get_post_terms( (int) $post['id'], Allergen::TAXONOMY, [ 'fields' => 'slugs' ] );
					return is_array( $terms ) ? $terms : [];
				},
				'schema'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			]
		);
	}

	public static function get_macros( int $ingredient_id ): array {
		// Served from the cached catalogue on the hot path (cart/checkout/emails);
		// falls back to a direct read for ids not in the published catalogue.
		$cached = IngredientCatalog::macros( $ingredient_id );
		if ( null !== $cached ) {
			return $cached;
		}
		$macros = get_post_meta( $ingredient_id, '_fn_macros', true );
		$macros = is_array( $macros ) ? $macros : [];
		return [
			'kcal'      => (float) ( $macros['kcal'] ?? 0 ),
			'protein_g' => (float) ( $macros['protein_g'] ?? 0 ),
			'carbs_g'   => (float) ( $macros['carbs_g'] ?? 0 ),
			'fat_g'     => (float) ( $macros['fat_g'] ?? 0 ),
		];
	}

	public static function get_type_slug( int $ingredient_id ): string {
		$terms = wp_get_post_terms( $ingredient_id, IngredientType::TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
	}
}

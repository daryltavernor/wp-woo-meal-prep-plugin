<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use WP_Post;

/**
 * The "Food safety" box on the Ingredient edit screen, and the resolved
 * per-ingredient values the Cook & Pack Record prints.
 *
 * Stored as one array in `_fn_safety`. Every field may be left blank, in which
 * case a sensible default applies: the cooking method follows the ingredient
 * type (proteins roast, carbs boil, greens steam, sweets are bought in), the
 * target core temperature and shelf life come from Food Safety Settings, and
 * the batch short code is derived from the name. The client therefore only
 * has to correct the exceptions.
 */
final class IngredientSafety {

	public const META = '_fn_safety';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . Ingredient::POST_TYPE, [ $this, 'save' ], 20, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'fn_ingredient_food_safety',
			__( 'Food safety', 'fastnutrition-mealprep' ),
			[ $this, 'render' ],
			Ingredient::POST_TYPE,
			'normal',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'fn_save_ingredient_safety', 'fn_ingredient_safety_nonce' );
		$raw      = self::raw( $post->ID );
		$type     = Ingredient::get_type_slug( $post->ID );
		$resolved = self::resolve( $post->ID, $type );
		$settings = FoodSafetySettings::get();

		echo '<p class="description">' . esc_html__( 'Used by the Cook & Pack Record and Prep Records. Leave a field blank to use the default shown beside it.', 'fastnutrition-mealprep' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		// Method.
		echo '<tr><th><label for="fn_safety_method">' . esc_html__( 'Cooking method', 'fastnutrition-mealprep' ) . '</label></th><td>';
		echo '<select id="fn_safety_method" name="fn_safety[method]">';
		printf( '<option value="">%s</option>', esc_html( sprintf( /* translators: %s: default method label */ __( 'Default for type (%s)', 'fastnutrition-mealprep' ), FoodSafetySettings::method_label( self::default_method_for_type( $type ) ) ) ) );
		foreach ( FoodSafetySettings::methods() as $slug => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( (string) ( $raw['method'] ?? '' ), $slug, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		// Target core temperature.
		printf(
			'<tr><th><label for="fn_safety_target_c">%s</label></th><td><input type="number" step="0.5" id="fn_safety_target_c" name="fn_safety[target_c]" value="%s" placeholder="%s" style="width:110px" /> °C <p class="description">%s</p></td></tr>',
			esc_html__( 'Target core temperature', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $raw['target_c'] ?? '' ) ),
			esc_attr( (string) $settings['cook_core_c'] ),
			esc_html__( 'The probe reading every batch must reach. Not used for no-cook items.', 'fastnutrition-mealprep' )
		);

		// Typical cook.
		printf(
			'<tr><th><label for="fn_safety_typical">%s</label></th><td><input type="text" class="regular-text" id="fn_safety_typical" name="fn_safety[typical]" value="%s" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Typical cook (reminder)', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $raw['typical'] ?? '' ) ),
			esc_attr__( 'e.g. 180 °C fan, 25–30 min', 'fastnutrition-mealprep' ),
			esc_html__( 'Printed under the method as a prompt for staff. It is not a record.', 'fastnutrition-mealprep' )
		);

		// Cooling.
		$cooling = (string) ( $raw['cooling'] ?? '' );
		printf(
			'<tr><th>%s</th><td><select name="fn_safety[cooling]"><option value="" %s>%s</option><option value="1" %s>%s</option><option value="0" %s>%s</option></select></td></tr>',
			esc_html__( 'Cooling step', 'fastnutrition-mealprep' ),
			selected( $cooling, '', false ),
			esc_html__( 'Default (yes for cooked items)', 'fastnutrition-mealprep' ),
			selected( $cooling, '1', false ),
			esc_html__( 'Yes — record cooled-by time', 'fastnutrition-mealprep' ),
			selected( $cooling, '0', false ),
			esc_html__( 'No', 'fastnutrition-mealprep' )
		);

		// Shelf life.
		printf(
			'<tr><th><label for="fn_safety_shelf">%s</label></th><td><input type="number" step="1" min="1" id="fn_safety_shelf" name="fn_safety[shelf_life_days]" value="%s" placeholder="%s" style="width:90px" /> %s<p class="description">%s</p></td></tr>',
			esc_html__( 'Shelf life', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $raw['shelf_life_days'] ?? '' ) ),
			esc_attr( (string) $settings['shelf_life_days'] ),
			esc_html__( 'days', 'fastnutrition-mealprep' ),
			esc_html__( 'Sets the use-by printed on the record. The fulfilment day counts as day 1.', 'fastnutrition-mealprep' )
		);

		// Storage.
		echo '<tr><th>' . esc_html__( 'Storage', 'fastnutrition-mealprep' ) . '</th><td><select name="fn_safety[storage]">';
		foreach ( FoodSafetySettings::storage_labels() as $slug => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $slug ), selected( (string) ( $raw['storage'] ?? 'chilled' ), $slug, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		// Short code.
		printf(
			'<tr><th><label for="fn_safety_code">%s</label></th><td><input type="text" id="fn_safety_code" name="fn_safety[short_code]" value="%s" placeholder="%s" maxlength="6" style="width:90px;text-transform:uppercase" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Batch short code', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $raw['short_code'] ?? '' ) ),
			esc_attr( $resolved['short_code'] ),
			esc_html__( 'Three or four letters used in batch codes, e.g. 261128-CHC-1.', 'fastnutrition-mealprep' )
		);

		echo '</tbody></table>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['fn_ingredient_safety_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_ingredient_safety_nonce'] ) ), 'fn_save_ingredient_safety' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['fn_safety'] ) && is_array( $_POST['fn_safety'] ) ? wp_unslash( $_POST['fn_safety'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.

		$method = sanitize_key( (string) ( $in['method'] ?? '' ) );
		if ( '' !== $method && ! array_key_exists( $method, FoodSafetySettings::methods() ) ) {
			$method = '';
		}
		$storage = sanitize_key( (string) ( $in['storage'] ?? 'chilled' ) );
		if ( ! in_array( $storage, FoodSafetySettings::STORAGE_KINDS, true ) ) {
			$storage = 'chilled';
		}
		$cooling = (string) ( $in['cooling'] ?? '' );
		$code    = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['short_code'] ?? '' ) ) ?? '' );

		update_post_meta(
			$post_id,
			self::META,
			[
				'method'          => $method,
				'target_c'        => is_numeric( $in['target_c'] ?? null ) ? (float) $in['target_c'] : '',
				'typical'         => sanitize_text_field( (string) ( $in['typical'] ?? '' ) ),
				'cooling'         => in_array( $cooling, [ '0', '1' ], true ) ? $cooling : '',
				'shelf_life_days' => is_numeric( $in['shelf_life_days'] ?? null ) && (int) $in['shelf_life_days'] > 0 ? (int) $in['shelf_life_days'] : '',
				'storage'         => $storage,
				'short_code'      => substr( $code, 0, 6 ),
			]
		);
	}

	/**
	 * The saved values as entered (blanks preserved).
	 *
	 * @return array<string,mixed>
	 */
	public static function raw( int $ingredient_id ): array {
		$raw = get_post_meta( $ingredient_id, self::META, true );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Values with every blank resolved to its default.
	 *
	 * @return array{method:string,method_label:string,no_cook:bool,target_c:float,typical:string,cooling:bool,shelf_life_days:int,storage:string,short_code:string}
	 */
	public static function resolve( int $ingredient_id, string $type_slug = '' ): array {
		$raw      = self::raw( $ingredient_id );
		$settings = FoodSafetySettings::get();
		if ( '' === $type_slug ) {
			$type_slug = Ingredient::get_type_slug( $ingredient_id );
		}
		$method  = (string) ( $raw['method'] ?? '' );
		$method  = '' !== $method ? $method : self::default_method_for_type( $type_slug );
		$no_cook = 'no_cook' === $method;
		$cooling = (string) ( $raw['cooling'] ?? '' );
		$code    = (string) ( $raw['short_code'] ?? '' );

		return [
			'method'          => $method,
			'method_label'    => FoodSafetySettings::method_label( $method ),
			'no_cook'         => $no_cook,
			'target_c'        => is_numeric( $raw['target_c'] ?? null ) ? (float) $raw['target_c'] : (float) $settings['cook_core_c'],
			'typical'         => (string) ( $raw['typical'] ?? '' ),
			'cooling'         => '' !== $cooling ? '1' === $cooling : ! $no_cook,
			'shelf_life_days' => is_numeric( $raw['shelf_life_days'] ?? null ) && (int) $raw['shelf_life_days'] > 0 ? (int) $raw['shelf_life_days'] : (int) $settings['shelf_life_days'],
			'storage'         => (string) ( $raw['storage'] ?? 'chilled' ),
			'short_code'      => '' !== $code ? $code : self::suggest_code( (string) get_the_title( $ingredient_id ) ),
		];
	}

	public static function default_method_for_type( string $type_slug ): string {
		switch ( $type_slug ) {
			case 'protein':
			case 'set_meal':
				return 'oven';
			case 'carb':
				return 'boil';
			case 'greens':
				return 'steam';
			case 'sweet':
				return 'no_cook';
			default:
				return 'oven';
		}
	}

	/**
	 * A three-letter code from a name.
	 *
	 * Multi-word names take the initials of their first three words ("Chicken
	 * Salad Wrap" → CSW, "Jerk Chicken (Bulk)" → JCB), padded from the first
	 * word's consonants when there are only two ("Beef Steak" → BSF, "Jerk
	 * Chicken" → JCR). Single words take the first letter and the next
	 * consonants ("Broccoli" → BRC, "Sweetcorn" → SWT). Names that still
	 * collide on a day are told apart with a digit by BatchCodes.
	 */
	public static function suggest_code( string $name ): string {
		$words = preg_split( '/[^A-Z]+/', strtoupper( remove_accents( $name ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( empty( $words ) ) {
			return 'ITM';
		}
		$first_rest = substr( $words[0], 1 );
		$fill       = (string) preg_replace( '/[AEIOU]/', '', $first_rest ) . $first_rest;
		if ( count( $words ) >= 2 ) {
			$code = '';
			foreach ( array_slice( $words, 0, 3 ) as $w ) {
				$code .= $w[0];
			}
			if ( strlen( $code ) < 3 ) {
				$code .= substr( $fill, 0, 3 - strlen( $code ) );
			}
			return substr( str_pad( $code, 3, 'X' ), 0, 3 );
		}
		if ( strlen( $words[0] ) < 3 ) {
			return str_pad( $words[0], 3, 'X' );
		}
		return substr( $words[0][0] . $fill, 0, 3 );
	}
}

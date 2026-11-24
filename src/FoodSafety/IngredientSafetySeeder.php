<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

use FastNutrition\MealPrep\PostTypes\Ingredient;

/**
 * Suggested food-safety values for every ingredient, worked out from its name
 * and type: chicken roasts, steak is pan-cooked through, chilli and curry
 * simmer, rice boils and must be chilled within the hour, greens steam,
 * avocado and bought-in sweets are no-cook. The settings page previews the
 * suggestions and applies them in one click; the client then only corrects
 * the odd exception on the ingredient itself.
 */
final class IngredientSafetySeeder {

	/**
	 * Rules in priority order; the first rule with a keyword in the name wins.
	 * Keywords are matched case-insensitively as substrings. A rule may be
	 * limited to ingredient types.
	 *
	 * @return array<int,array{keywords:string[],types?:string[],values:array<string,mixed>}>
	 */
	public static function rules(): array {
		$cooked = [
			'target_c' => 75,
			'cooling'  => '1',
			'storage'  => 'chilled',
		];
		return [
			[
				'keywords' => [ 'flapjack', 'energy ball', 'cheesecake', 'overnight oats', 'brownie', 'cookie', 'protein bar', 'muffin', 'cake', 'treat' ],
				'values'   => [
					'method'          => 'no_cook',
					'typical'         => __( 'Bought in: check supplier use-by and allergen label on receipt, keep chilled', 'fastnutrition-mealprep' ),
					'cooling'         => '0',
					'shelf_life_days' => 5,
					'storage'         => 'chilled',
				],
			],
			[
				'keywords' => [ 'avocado', 'lettuce', 'salad leaves', 'cucumber', 'tomato', 'coleslaw', 'slaw', 'rocket', 'mixed leaves' ],
				'values'   => [
					'method'          => 'no_cook',
					'typical'         => __( 'Raw: wash in cold running water, prepare fresh, keep at 5 °C or below', 'fastnutrition-mealprep' ),
					'cooling'         => '0',
					'shelf_life_days' => 2,
					'storage'         => 'chilled',
				],
			],
			[
				'keywords' => [ 'sausage', 'bacon', 'blt', 'breakfast' ],
				'values'   => $cooked + [
					'method'  => 'grill',
					'typical' => __( 'Grill until cooked through and juices run clear, probe ≥ 75 °C; cool before assembling', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'steak' ],
				'values'   => $cooked + [
					'method'  => 'pan',
					'typical' => __( 'Griddle/pan, cook through for a chilled meal, probe ≥ 75 °C, rest 2 min', 'fastnutrition-mealprep' ),
				],
			],
			[
				// Assembled-cold set meals: the critical step is cooking the
				// protein, then chilling it before it goes into the salad or wrap.
				'keywords' => [ 'salad', 'wrap', 'sandwich', 'bagel' ],
				'types'    => [ 'set_meal' ],
				'values'   => $cooked + [
					'method'  => 'oven',
					'typical' => __( 'Cook the protein to ≥ 75 °C core, chill to ≤ 8 °C, then assemble cold and keep chilled', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'casserole', 'hotpot', 'bake', 'lasagne', 'cottage pie', 'shepherd' ],
				'values'   => $cooked + [
					'method'  => 'oven',
					'typical' => __( 'Oven 160 °C fan, 60–90 min covered; probe the centre ≥ 75 °C; cool in shallow trays', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'chilli', 'chili', 'bolognese', 'curry', 'stew', 'ragu', 'mince', 'balti', 'korma', 'tikka masala', 'goulash' ],
				'values'   => $cooked + [
					'method'  => 'pan',
					'typical' => __( 'Simmer 45–60 min, stir regularly, probe the centre of the pan ≥ 75 °C; cool in shallow trays', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'chicken', 'turkey', 'quorn', 'tofu', 'pork', 'lamb' ],
				'values'   => $cooked + [
					'method'  => 'oven',
					'typical' => __( 'Oven 180 °C fan, 25–35 min; probe the thickest piece ≥ 75 °C, no pink', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'salmon', 'cod', 'fish', 'prawn', 'tuna', 'haddock' ],
				'values'   => $cooked + [
					'method'  => 'oven',
					'typical' => __( 'Oven 180 °C fan, 12–15 min until it flakes, probe ≥ 75 °C', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'egg' ],
				'values'   => $cooked + [
					'method'  => 'boil',
					'typical' => __( 'Boil 9–10 min (hard), cool in iced water, peel, keep chilled', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'rice', 'quinoa', 'couscous', 'bulgur' ],
				'values'   => $cooked + [
					'method'          => 'boil',
					'typical'         => __( 'Boil until tender (white 12–15 min, brown 25–30), drain, spread thin and chill fast', 'fastnutrition-mealprep' ),
					'cool_within_min' => 60,
				],
			],
			[
				'keywords' => [ 'pasta', 'noodle', 'spaghetti', 'penne', 'fusilli' ],
				'values'   => $cooked + [
					'method'  => 'boil',
					'typical' => __( 'Boil 9–11 min, refresh in cold water, drain well', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'jacket' ],
				'values'   => $cooked + [
					'method'  => 'oven',
					'typical' => __( 'Oven 200 °C fan, 60–75 min, probe centre ≥ 75 °C', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'potato', 'mash' ],
				'values'   => $cooked + [
					'method'  => 'boil',
					'typical' => __( 'Boil 15–20 min until tender, drain, cool rapidly', 'fastnutrition-mealprep' ),
				],
			],
			[
				'keywords' => [ 'oatcake', 'bread', 'wrap', 'naan', 'bagel', 'tortilla', 'pitta', 'roll' ],
				'types'    => [ 'carb', 'greens', 'sweet' ],
				'values'   => [
					'method'          => 'no_cook',
					'typical'         => __( 'Bought in: check supplier use-by on receipt', 'fastnutrition-mealprep' ),
					'cooling'         => '0',
					'shelf_life_days' => 3,
					'storage'         => 'ambient',
				],
			],
			[
				'keywords' => [ 'broccoli', 'bean', 'peas', 'sweetcorn', 'corn', 'spinach', 'cabbage', 'kale', 'carrot', 'asparagus', 'veg', 'cauliflower', 'mangetout', 'courgette', 'pepper', 'onion', 'mushroom', 'leek', 'sprout' ],
				'values'   => $cooked + [
					'method'  => 'steam',
					'typical' => __( 'Steam 4–8 min until tender-crisp, refresh in iced water, drain well', 'fastnutrition-mealprep' ),
				],
			],
		];
	}

	/**
	 * The suggested value set for one ingredient (stored shape, so blanks are
	 * allowed where nothing better than the default is known).
	 *
	 * @return array<string,mixed>
	 */
	public static function suggest( int $ingredient_id, string $name, string $type_slug ): array {
		$haystack = strtolower( remove_accents( $name ) );
		$values   = null;
		foreach ( self::rules() as $rule ) {
			if ( ! empty( $rule['types'] ) && ! in_array( $type_slug, $rule['types'], true ) ) {
				continue;
			}
			foreach ( $rule['keywords'] as $kw ) {
				// Whole-word prefix match: "bean" matches "beans" and "potato"
				// matches "potatoes", but "ragu" no longer matches "asparagus"
				// and "cake" no longer matches "oatcake".
				if ( preg_match( '/(?<![a-z])' . preg_quote( $kw, '/' ) . '/', $haystack ) ) {
					$values = $rule['values'];
					break 2;
				}
			}
		}
		if ( null === $values ) {
			$values = self::fallback_for_type( $type_slug );
		}
		$values['short_code'] = IngredientSafety::suggest_code( $name );
		return IngredientSafety::sanitise( $values );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function fallback_for_type( string $type_slug ): array {
		switch ( $type_slug ) {
			case 'sweet':
				return [
					'method'          => 'no_cook',
					'typical'         => __( 'Bought in: check supplier use-by and allergen label on receipt, keep chilled', 'fastnutrition-mealprep' ),
					'cooling'         => '0',
					'shelf_life_days' => 5,
				];
			case 'carb':
				return [
					'method'   => 'boil',
					'target_c' => 75,
					'cooling'  => '1',
					'typical'  => __( 'Boil until tender, drain, cool rapidly', 'fastnutrition-mealprep' ),
				];
			case 'greens':
				return [
					'method'   => 'steam',
					'target_c' => 75,
					'cooling'  => '1',
					'typical'  => __( 'Steam until tender-crisp, refresh in iced water, drain well', 'fastnutrition-mealprep' ),
				];
			case 'set_meal':
				return [
					'method'   => 'oven',
					'target_c' => 75,
					'cooling'  => '1',
					'typical'  => __( 'Cook every component to ≥ 75 °C core, chill within 90 min, assemble cold', 'fastnutrition-mealprep' ),
				];
			default:
				return [
					'method'   => 'oven',
					'target_c' => 75,
					'cooling'  => '1',
					'typical'  => __( 'Oven 180 °C fan, probe the thickest piece ≥ 75 °C', 'fastnutrition-mealprep' ),
				];
		}
	}

	/**
	 * Every reminder text a rule or fallback can write, so values applied
	 * before the "suggested" stamp existed can still be told apart from hand
	 * edits.
	 *
	 * @return string[]
	 */
	public static function known_typicals(): array {
		$out = [];
		foreach ( self::rules() as $rule ) {
			$out[] = (string) ( $rule['values']['typical'] ?? '' );
		}
		foreach ( [ 'protein', 'carb', 'greens', 'set_meal', 'sweet', '' ] as $type ) {
			$out[] = (string) ( self::fallback_for_type( $type )['typical'] ?? '' );
		}
		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * Every published ingredient with its current and suggested values.
	 *
	 * @return array<int,array{id:int,name:string,type:string,blank:bool,untouched:bool,current:array<string,mixed>,suggested:array<string,mixed>}>
	 */
	public static function preview(): array {
		$posts = get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		$ids   = array_map( static fn( $p ) => (int) $p->ID, $posts );
		$types = Ingredient::get_type_slugs_for( $ids );
		$out   = [];
		foreach ( $posts as $p ) {
			$id    = (int) $p->ID;
			$type  = (string) ( $types[ $id ] ?? '' );
			$out[] = [
				'id'        => $id,
				'name'      => (string) $p->post_title,
				'type'      => $type,
				'blank'     => IngredientSafety::is_blank( $id ),
				'untouched' => IngredientSafety::is_untouched( $id ),
				'current'   => IngredientSafety::raw( $id ),
				'suggested' => self::suggest( $id, (string) $p->post_title, $type ),
			];
		}
		return $out;
	}

	/**
	 * Write the suggestions, stamped so a later apply can refresh them when the
	 * rules improve. With $overwrite false only ingredients nobody has edited by
	 * hand (blank, or still holding a suggestion) are touched. Returns the
	 * number updated.
	 */
	public static function apply( bool $overwrite ): int {
		$n = 0;
		foreach ( self::preview() as $row ) {
			if ( ! $overwrite && ! $row['untouched'] ) {
				continue;
			}
			update_post_meta( $row['id'], IngredientSafety::META, $row['suggested'] + [ 'suggested' => '1' ] );
			$n++;
		}
		return $n;
	}
}

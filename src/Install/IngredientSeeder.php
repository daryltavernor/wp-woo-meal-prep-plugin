<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Install;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

/**
 * One-time seeder for Fast Nutrition's existing ingredient catalogue.
 *
 * Data is baked into self::data() so the plugin can be activated on any
 * environment without an upload step. Seeding is idempotent — items
 * already present (matched by post title) are skipped.
 */
final class IngredientSeeder {

	public const DONE_OPTION = 'fn_ingredient_seed_done';

	/**
	 * Runs the seeder. Returns the number of ingredients created.
	 */
	public static function seed( bool $force = false ): int {
		if ( ! $force && get_option( self::DONE_OPTION ) ) {
			return 0;
		}

		$created = 0;
		foreach ( self::data() as $row ) {
			$title = 'bulk' === $row['tier'] ? $row['name'] . ' (Bulk)' : $row['name'];
			if ( self::title_exists( $title ) ) {
				continue;
			}
			$post_id = wp_insert_post(
				[
					'post_type'   => Ingredient::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				],
				true
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			update_post_meta(
				$post_id,
				'_fn_macros',
				[
					'kcal'      => (float) $row['kcal'],
					'protein_g' => (float) $row['protein'],
					'carbs_g'   => (float) $row['carbs'],
					'fat_g'     => (float) $row['fat'],
				]
			);
			update_post_meta( $post_id, '_fn_tier', $row['tier'] );
			update_post_meta( $post_id, '_fn_active', true );
			update_post_meta( $post_id, '_fn_price_delta', 0 );

			$term = get_term_by( 'slug', $row['type'], IngredientType::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_object_terms( $post_id, [ (int) $term->term_id ], IngredientType::TAXONOMY, false );
			}
			$created++;
		}

		update_option( self::DONE_OPTION, 1, false );
		return $created;
	}

	private static function title_exists( string $title ): bool {
		$found = get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'title'          => $title,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return ! empty( $found );
	}

	/**
	 * @return array<int,array{tier:string,type:string,name:string,protein:float,carbs:float,fat:float,kcal:float}>
	 */
	private static function data(): array {
		// Format: tier, type, name, protein, carbs, fat, kcal.
		// type is one of: protein, carb, greens, set_meal, sweet.
		return [
			// Carbs - Standard.
			[ 'tier' => 'standard', 'type' => 'carb', 'name' => 'Brown Rice', 'protein' => 4.9, 'carbs' => 40, 'fat' => 2.6, 'kcal' => 209 ],
			[ 'tier' => 'standard', 'type' => 'carb', 'name' => 'Basmati Rice', 'protein' => 4.2, 'carbs' => 39.7, 'fat' => 1.3, 'kcal' => 188 ],
			[ 'tier' => 'standard', 'type' => 'carb', 'name' => 'Wholemeal Pasta', 'protein' => 7.8, 'carbs' => 42, 'fat' => 1.2, 'kcal' => 210 ],
			[ 'tier' => 'standard', 'type' => 'carb', 'name' => 'Sweet Potatoes', 'protein' => 2.3, 'carbs' => 30, 'fat' => 0.8, 'kcal' => 129 ],
			[ 'tier' => 'standard', 'type' => 'carb', 'name' => 'New Potatoes', 'protein' => 3, 'carbs' => 30, 'fat' => 0.5, 'kcal' => 135 ],
			// Carbs - Bulk.
			[ 'tier' => 'bulk', 'type' => 'carb', 'name' => 'New Potatoes', 'protein' => 5, 'carbs' => 50, 'fat' => 1, 'kcal' => 225 ],
			[ 'tier' => 'bulk', 'type' => 'carb', 'name' => 'Sweet Potatoes', 'protein' => 3.8, 'carbs' => 50, 'fat' => 1.3, 'kcal' => 215 ],
			[ 'tier' => 'bulk', 'type' => 'carb', 'name' => 'Brown Rice', 'protein' => 8, 'carbs' => 65, 'fat' => 4.3, 'kcal' => 348 ],
			[ 'tier' => 'bulk', 'type' => 'carb', 'name' => 'Wholemeal Pasta', 'protein' => 8, 'carbs' => 65, 'fat' => 6, 'kcal' => 335 ],
			[ 'tier' => 'bulk', 'type' => 'carb', 'name' => 'Basmati Rice', 'protein' => 5.4, 'carbs' => 60, 'fat' => 1.9, 'kcal' => 278 ],

			// Greens - Standard.
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Broccoli', 'protein' => 2, 'carbs' => 4, 'fat' => 0, 'kcal' => 24 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Asparagus', 'protein' => 1.6, 'carbs' => 3, 'fat' => 0, 'kcal' => 19 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Peas', 'protein' => 5, 'carbs' => 7.5, 'fat' => 0, 'kcal' => 52 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Avocado', 'protein' => 1, 'carbs' => 2, 'fat' => 7.3, 'kcal' => 78 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Green Beans', 'protein' => 2.1, 'carbs' => 3.1, 'fat' => 0, 'kcal' => 21 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Spinach', 'protein' => 1.8, 'carbs' => 2, 'fat' => 0, 'kcal' => 16 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Cabbage', 'protein' => 1.8, 'carbs' => 3, 'fat' => 1, 'kcal' => 24 ],
			[ 'tier' => 'standard', 'type' => 'greens', 'name' => 'Sweetcorn', 'protein' => 2.2, 'carbs' => 9, 'fat' => 1, 'kcal' => 46 ],
			// Greens - Bulk.
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Broccoli', 'protein' => 3.1, 'carbs' => 6, 'fat' => 0, 'kcal' => 36 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Green Beans', 'protein' => 3.2, 'carbs' => 5.8, 'fat' => 0, 'kcal' => 36 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Asparagus', 'protein' => 2.8, 'carbs' => 5.8, 'fat' => 0, 'kcal' => 24 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Spinach', 'protein' => 3.2, 'carbs' => 4, 'fat' => 0, 'kcal' => 32 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Peas', 'protein' => 8.5, 'carbs' => 10, 'fat' => 0, 'kcal' => 74 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Avocado', 'protein' => 2, 'carbs' => 4, 'fat' => 14.6, 'kcal' => 156 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Cabbage', 'protein' => 2.7, 'carbs' => 4.5, 'fat' => 1.5, 'kcal' => 48 ],
			[ 'tier' => 'bulk', 'type' => 'greens', 'name' => 'Sweetcorn', 'protein' => 3.3, 'carbs' => 13.5, 'fat' => 1.5, 'kcal' => 69 ],

			// Proteins - Standard.
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Tikka Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Plain Chicken', 'protein' => 46, 'carbs' => 0, 'fat' => 4.8, 'kcal' => 231 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'BBQ Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Jerk Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Cajun Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Salt And Pepper Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Garlic Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Chinese Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Doubled Smoked Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Peri Peri Chicken', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Beef Steak', 'protein' => 33.6, 'carbs' => 0, 'fat' => 6.7, 'kcal' => 191 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Beef Chilli', 'protein' => 33, 'carbs' => 15, 'fat' => 8, 'kcal' => 269 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Chicken Casserole', 'protein' => 33, 'carbs' => 11, 'fat' => 4, 'kcal' => 212 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chilli', 'protein' => 15.3, 'carbs' => 11.5, 'fat' => 2.6, 'kcal' => 132 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Bolognese', 'protein' => 15.8, 'carbs' => 10.8, 'fat' => 2.9, 'kcal' => 133 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Flavour Of The Month', 'protein' => 48, 'carbs' => 2, 'fat' => 6.5, 'kcal' => 256 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chicken - Chinese', 'protein' => 14, 'carbs' => 2.4, 'fat' => 2.6, 'kcal' => 100 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chicken - BBQ', 'protein' => 14, 'carbs' => 2.4, 'fat' => 2.6, 'kcal' => 100 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chicken - Tikka', 'protein' => 14, 'carbs' => 2.4, 'fat' => 2.6, 'kcal' => 100 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chicken - Peri Peri', 'protein' => 14, 'carbs' => 2.4, 'fat' => 2.6, 'kcal' => 100 ],
			[ 'tier' => 'standard', 'type' => 'protein', 'name' => 'Quorn Chicken - Salt And Pepper', 'protein' => 14, 'carbs' => 2.4, 'fat' => 2.6, 'kcal' => 100 ],
			// Proteins - Bulk.
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Tikka Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Jerk Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Salt And Pepper Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Plain Chicken', 'protein' => 67, 'carbs' => 0, 'fat' => 7.7, 'kcal' => 338 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'BBQ Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Garlic Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Cajun Chicken', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Beef Chilli', 'protein' => 59, 'carbs' => 27, 'fat' => 14.4, 'kcal' => 473 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Chicken Casserole', 'protein' => 62, 'carbs' => 19, 'fat' => 8, 'kcal' => 367 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Beef Steak', 'protein' => 45, 'carbs' => 0, 'fat' => 11.5, 'kcal' => 286 ],
			[ 'tier' => 'bulk', 'type' => 'protein', 'name' => 'Flavour Of The Month', 'protein' => 72, 'carbs' => 3, 'fat' => 9.7, 'kcal' => 387 ],

			// Set Meals - Standard.
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chicken Salad Wrap Of The Day', 'protein' => 54, 'carbs' => 51, 'fat' => 8.4, 'kcal' => 496 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chicken Pasta Salad', 'protein' => 55, 'carbs' => 43, 'fat' => 6.7, 'kcal' => 452 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Beef Bolognese Served With Pasta And Green Beans', 'protein' => 48, 'carbs' => 58, 'fat' => 7.3, 'kcal' => 489 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chinese Chicken Curry', 'protein' => 43.2, 'carbs' => 56, 'fat' => 10, 'kcal' => 488 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chicken Sausage Oatcake x2', 'protein' => 41, 'carbs' => 51, 'fat' => 14, 'kcal' => 496 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Breakfast Special', 'protein' => 46, 'carbs' => 13, 'fat' => 18, 'kcal' => 400 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'BLT Bagel', 'protein' => 13.5, 'carbs' => 25, 'fat' => 14, 'kcal' => 275 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Beef Chilli And Jacket Potato Served With Sweet Corn', 'protein' => 38, 'carbs' => 54, 'fat' => 10, 'kcal' => 455 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chicken Balti Curry Served With Basmati Rice And Spinach', 'protein' => 42, 'carbs' => 49, 'fat' => 11, 'kcal' => 463 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Chicken Sausage & Sweet Potato Mash Served With Cabbage And Onion Gravy', 'protein' => 19, 'carbs' => 55, 'fat' => 15.9, 'kcal' => 438 ],
			[ 'tier' => 'standard', 'type' => 'set_meal', 'name' => 'Tex Mex Chicken Naan Bread Served With Onions And Peppers', 'protein' => 52, 'carbs' => 24, 'fat' => 8.5, 'kcal' => 379 ],
			// Set Meals - Bulk.
			[ 'tier' => 'bulk', 'type' => 'set_meal', 'name' => 'Beef Bolognese Served With Pasta And Green Beans', 'protein' => 74, 'carbs' => 89, 'fat' => 11.6, 'kcal' => 737 ],
			[ 'tier' => 'bulk', 'type' => 'set_meal', 'name' => 'Chinese Chicken Curry', 'protein' => 71, 'carbs' => 78, 'fat' => 18, 'kcal' => 758 ],
			[ 'tier' => 'bulk', 'type' => 'set_meal', 'name' => 'Chicken Balti Curry Served With Basmati Rice And Spinach', 'protein' => 70, 'carbs' => 77, 'fat' => 18, 'kcal' => 723 ],
			[ 'tier' => 'bulk', 'type' => 'set_meal', 'name' => 'Chicken Sausage, Sweet Potato Mash Served With Cabbage And Onion Gravy', 'protein' => 32, 'carbs' => 84, 'fat' => 27.8, 'kcal' => 719 ],
			[ 'tier' => 'bulk', 'type' => 'set_meal', 'name' => 'Beef Chilli And Jacket Potato Served With Sweet Corn', 'protein' => 66.5, 'carbs' => 85, 'fat' => 17, 'kcal' => 759 ],

			// Sweets.
			[ 'tier' => 'standard', 'type' => 'sweet', 'name' => 'Peanut Butter Protein Flapjack', 'protein' => 28, 'carbs' => 58, 'fat' => 28, 'kcal' => 596 ],
			[ 'tier' => 'standard', 'type' => 'sweet', 'name' => 'Protein Energy Balls', 'protein' => 16, 'carbs' => 58, 'fat' => 19, 'kcal' => 471 ],
			[ 'tier' => 'standard', 'type' => 'sweet', 'name' => 'Lotus Biscoff Overnight Oats', 'protein' => 27.6, 'carbs' => 58, 'fat' => 15.9, 'kcal' => 485.5 ],
		];
	}
}

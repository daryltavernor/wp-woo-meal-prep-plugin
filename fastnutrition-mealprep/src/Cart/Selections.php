<?php
/**
 * Validates meal-builder selections before items are added to the cart, and stores the selection on the cart item.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Taxonomies\IngredientType;
use WC_Product;

final class Selections {

	public const CART_KEY = 'fn_selection';

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate' ], 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'attach' ], 10, 2 );
		add_filter( 'woocommerce_add_cart_item', [ $this, 'recalculate_price' ], 10, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'recalculate_price' ], 10, 1 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display' ], 10, 2 );
	}

	/**
	 * Pull the selection from POST when a meal product is added to the cart.
	 */
	public function attach( array $cart_item_data, int $product_id ): array {
		if ( ! MealProduct::is_meal( $product_id ) ) {
			return $cart_item_data;
		}
		$raw = isset( $_POST['fn_selection'] ) ? wp_unslash( $_POST['fn_selection'] ) : '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
		$selection = $this->sanitize( is_array( $decoded ) ? $decoded : [], $product_id );
		if ( null === $selection ) {
			return $cart_item_data;
		}
		$cart_item_data[ self::CART_KEY ] = $selection;
		// Unique hash so combinations with different selections don't merge in the cart.
		$cart_item_data['fn_hash'] = md5( wp_json_encode( $selection ) );
		return $cart_item_data;
	}

	public function validate( bool $passed, int $product_id, int $quantity ): bool {
		if ( ! MealProduct::is_meal( $product_id ) ) {
			return $passed;
		}
		$raw = isset( $_POST['fn_selection'] ) ? wp_unslash( $_POST['fn_selection'] ) : '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
		if ( null === $this->sanitize( is_array( $decoded ) ? $decoded : [], $product_id ) ) {
			wc_add_notice( __( 'Please complete your meal selection before adding to the cart.', 'fastnutrition-mealprep' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Build the canonical selection array, or null if invalid.
	 *
	 * @return array{mode:string,protein_id:?int,carb_id:?int,greens_ids:int[],set_meal_id:?int,addons:array<int,array{id:string,label:string,price:float}>,tier:string}|null
	 */
	public function sanitize( array $input, int $product_id ): ?array {
		$config  = MealProduct::config( $product_id );
		$mode    = isset( $input['mode'] ) && 'set' === $input['mode'] ? 'set' : 'build';
		$addons  = $this->sanitize_addons( $input['addons'] ?? [], $product_id );
		$tier    = $config['tier'];

		if ( 'set' === $mode ) {
			if ( ! $config['allow_set_meal'] ) {
				return null;
			}
			$set_id = isset( $input['set_meal_id'] ) ? (int) $input['set_meal_id'] : 0;
			if ( $set_id <= 0 || ! $this->ingredient_allowed( $set_id, IngredientType::TERM_SET_MEAL, $config['allowed_set_meal'] ) ) {
				return null;
			}
			return [
				'mode'        => 'set',
				'protein_id'  => null,
				'carb_id'     => null,
				'greens_ids'  => [],
				'set_meal_id' => $set_id,
				'addons'      => $addons,
				'tier'        => $tier,
			];
		}

		$protein = isset( $input['protein_id'] ) ? (int) $input['protein_id'] : 0;
		if ( $protein <= 0 || ! $this->ingredient_allowed( $protein, IngredientType::TERM_PROTEIN, $config['allowed_protein'] ) ) {
			return null;
		}

		$greens = array_values( array_unique( array_map( 'intval', (array) ( $input['greens_ids'] ?? [] ) ) ) );
		$greens = array_values( array_filter( $greens ) );
		$carb   = isset( $input['carb_id'] ) ? (int) $input['carb_id'] : 0;

		if ( 0 === $carb ) {
			// Double greens path — requires allow_double and exactly 2 greens.
			if ( ! $config['allow_double'] || count( $greens ) !== 2 ) {
				return null;
			}
		} else {
			if ( ! $this->ingredient_allowed( $carb, IngredientType::TERM_CARB, $config['allowed_carb'] ) ) {
				return null;
			}
			if ( count( $greens ) !== 1 ) {
				return null;
			}
		}

		foreach ( $greens as $g ) {
			if ( ! $this->ingredient_allowed( $g, IngredientType::TERM_GREENS, $config['allowed_greens'] ) ) {
				return null;
			}
		}

		return [
			'mode'        => 'build',
			'protein_id'  => $protein,
			'carb_id'     => $carb > 0 ? $carb : null,
			'greens_ids'  => $greens,
			'set_meal_id' => null,
			'addons'      => $addons,
			'tier'        => $tier,
		];
	}

	private function ingredient_allowed( int $id, string $expected_type, array $allow_list ): bool {
		$ing = Ingredient::get( $id );
		if ( ! $ing || empty( $ing['active'] ) ) {
			return false;
		}
		if ( $ing['type'] !== $expected_type ) {
			return false;
		}
		if ( ! empty( $allow_list ) && ! in_array( $id, $allow_list, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @return array<int,array{id:string,label:string,price:float}>
	 */
	private function sanitize_addons( array $input, int $product_id ): array {
		$defs    = AddOnMeta::get( $product_id );
		$by_id   = [];
		foreach ( $defs as $def ) {
			$by_id[ $def['id'] ] = $def;
		}
		$out = [];
		foreach ( $input as $entry ) {
			$id = is_array( $entry ) ? ( $entry['id'] ?? '' ) : (string) $entry;
			$id = sanitize_text_field( (string) $id );
			if ( isset( $by_id[ $id ] ) ) {
				$out[] = [
					'id'    => $by_id[ $id ]['id'],
					'label' => $by_id[ $id ]['label'],
					'price' => (float) $by_id[ $id ]['price'],
				];
			}
		}
		return $out;
	}

	/**
	 * Overwrite the cart item price with base + Σ price_delta + Σ addons so that
	 * every display (cart, mini-cart, checkout, order email) uses the correct line price.
	 */
	public function recalculate_price( array $cart_item ): array {
		if ( empty( $cart_item[ self::CART_KEY ] ) || empty( $cart_item['data'] ) ) {
			return $cart_item;
		}
		/** @var WC_Product $product */
		$product   = $cart_item['data'];
		$selection = $cart_item[ self::CART_KEY ];
		$base      = (float) $product->get_price( 'edit' );

		$delta = 0.0;
		foreach ( [ 'protein_id', 'carb_id', 'set_meal_id' ] as $key ) {
			if ( ! empty( $selection[ $key ] ) ) {
				$ing = Ingredient::get( (int) $selection[ $key ] );
				if ( $ing ) {
					$delta += (float) $ing['price_delta'];
				}
			}
		}
		foreach ( (array) ( $selection['greens_ids'] ?? [] ) as $gid ) {
			$ing = Ingredient::get( (int) $gid );
			if ( $ing ) {
				$delta += (float) $ing['price_delta'];
			}
		}
		foreach ( (array) ( $selection['addons'] ?? [] ) as $addon ) {
			$delta += (float) ( $addon['price'] ?? 0 );
		}

		$product->set_price( $base + $delta );
		return $cart_item;
	}

	/**
	 * Human-readable selection on cart/checkout line items.
	 */
	public function display( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$sel = $cart_item[ self::CART_KEY ];

		if ( 'set' === $sel['mode'] && ! empty( $sel['set_meal_id'] ) ) {
			$set = Ingredient::get( (int) $sel['set_meal_id'] );
			if ( $set ) {
				$item_data[] = [ 'name' => __( 'Set Meal', 'fastnutrition-mealprep' ), 'value' => $set['title'] ];
			}
		} else {
			if ( ! empty( $sel['protein_id'] ) ) {
				$ing = Ingredient::get( (int) $sel['protein_id'] );
				if ( $ing ) {
					$item_data[] = [ 'name' => __( 'Protein', 'fastnutrition-mealprep' ), 'value' => $ing['title'] ];
				}
			}
			if ( ! empty( $sel['carb_id'] ) ) {
				$ing = Ingredient::get( (int) $sel['carb_id'] );
				if ( $ing ) {
					$item_data[] = [ 'name' => __( 'Carb', 'fastnutrition-mealprep' ), 'value' => $ing['title'] ];
				}
			}
			$greens_names = [];
			foreach ( (array) $sel['greens_ids'] as $gid ) {
				$ing = Ingredient::get( (int) $gid );
				if ( $ing ) {
					$greens_names[] = $ing['title'];
				}
			}
			if ( $greens_names ) {
				$item_data[] = [
					'name'  => count( $greens_names ) > 1 ? __( 'Greens (x2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' ),
					'value' => implode( ' + ', $greens_names ),
				];
			}
		}

		if ( ! empty( $sel['addons'] ) ) {
			$labels = array_map( static fn( array $a ): string => $a['label'], $sel['addons'] );
			$item_data[] = [ 'name' => __( 'Add-ons', 'fastnutrition-mealprep' ), 'value' => implode( ', ', $labels ) ];
		}

		return $item_data;
	}
}

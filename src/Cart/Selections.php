<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Products\StandaloneProduct;

final class Selections {

	public const CART_KEY = 'fn_selection';

	public function register(): void {
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'attach_selection' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_selection' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate' ], 10, 3 );
	}

	public function attach_selection( array $cart_item_data, int $product_id, int $variation_id ): array {
		if ( ! MealProduct::is_configurable( $product_id ) ) {
			return $cart_item_data;
		}

		$raw = [];
		if ( isset( $_REQUEST['fn_selection'] ) ) {
			$raw = is_string( $_REQUEST['fn_selection'] )
				? json_decode( wp_unslash( (string) $_REQUEST['fn_selection'] ), true )
				: (array) wp_unslash( $_REQUEST['fn_selection'] );
			$raw = is_array( $raw ) ? $raw : [];
		}

		$selection = self::normalize( $product_id, $raw );
		if ( empty( $selection ) ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_KEY ]      = $selection;
		$cart_item_data[ self::CART_KEY . '_hash' ] = md5( wp_json_encode( $selection ) );
		return $cart_item_data;
	}

	public function validate( bool $passed, int $product_id, int $quantity ): bool {
		if ( ! MealProduct::is_configurable( $product_id ) ) {
			return $passed;
		}
		$raw = isset( $_REQUEST['fn_selection'] )
			? ( is_string( $_REQUEST['fn_selection'] ) ? json_decode( wp_unslash( (string) $_REQUEST['fn_selection'] ), true ) : (array) wp_unslash( $_REQUEST['fn_selection'] ) )
			: [];
		$raw = is_array( $raw ) ? $raw : [];
		$selection = self::normalize( $product_id, $raw );

		if ( empty( $selection ) ) {
			wc_add_notice(
				StandaloneProduct::is_enabled( $product_id )
					? __( 'Please choose an option before adding to cart.', 'fastnutrition-mealprep' )
					: __( 'Please choose your meal ingredients before adding to cart.', 'fastnutrition-mealprep' ),
				'error'
			);
			return false;
		}

		$mode = $selection['mode'] ?? '';

		// Standalone selections are fully validated in normalize() (the chosen
		// item must be in the product's allow-list and of the configured type).
		if ( 'standalone' === $mode ) {
			return $passed;
		}

		$config = MealProduct::get_config( $product_id );
		if ( 'set' === $mode ) {
			if ( ! $config['allow_set_meal_mode'] || empty( $selection['set_meal_id'] ) ) {
				wc_add_notice( __( 'Please choose a valid set meal.', 'fastnutrition-mealprep' ), 'error' );
				return false;
			}
		} elseif ( 'build' === $mode ) {
			if ( empty( $selection['protein_id'] ) ) {
				wc_add_notice( __( 'Please choose a protein.', 'fastnutrition-mealprep' ), 'error' );
				return false;
			}
			$greens = $selection['greens_ids'] ?? [];
			if ( count( $greens ) === 2 && ! $config['allow_double_greens'] ) {
				wc_add_notice( __( 'Double greens is not available for this meal.', 'fastnutrition-mealprep' ), 'error' );
				return false;
			}
			if ( count( $greens ) !== 1 && count( $greens ) !== 2 ) {
				wc_add_notice( __( 'Please choose your greens.', 'fastnutrition-mealprep' ), 'error' );
				return false;
			}
			if ( count( $greens ) === 1 && empty( $selection['carb_id'] ) ) {
				wc_add_notice( __( 'Please choose a carb, or pick a second greens.', 'fastnutrition-mealprep' ), 'error' );
				return false;
			}
		} else {
			wc_add_notice( __( 'Invalid meal selection.', 'fastnutrition-mealprep' ), 'error' );
			return false;
		}

		return $passed;
	}

	public function display_selection( array $item_data, array $cart_item ): array {
		$selection = $cart_item[ self::CART_KEY ] ?? null;
		if ( ! is_array( $selection ) ) {
			return $item_data;
		}
		foreach ( Selection::composition_pairs( $selection ) as $pair ) {
			$item_data[] = $pair;
		}
		$addons = Selection::addons_summary( $selection );
		if ( '' !== $addons ) {
			$item_data[] = [
				'key'   => __( 'Add-ons', 'fastnutrition-mealprep' ),
				'value' => $addons,
			];
		}
		return $item_data;
	}

	public static function normalize( int $product_id, array $raw ): array {
		// Standalone products: a single chosen item of the configured type.
		if ( StandaloneProduct::is_enabled( $product_id ) ) {
			return self::normalize_standalone( $product_id, $raw );
		}

		$config = MealProduct::get_config( $product_id );
		if ( ! $config['is_meal'] ) {
			return [];
		}

		$mode = isset( $raw['mode'] ) && in_array( $raw['mode'], [ 'build', 'set' ], true ) ? (string) $raw['mode'] : 'build';

		if ( 'set' === $mode && $config['allow_set_meal_mode'] ) {
			$set_meal_id = isset( $raw['set_meal_id'] ) ? (int) $raw['set_meal_id'] : 0;
			if ( ! $set_meal_id || ( ! empty( $config['allowed_set_meals'] ) && ! in_array( $set_meal_id, $config['allowed_set_meals'], true ) ) ) {
				return [];
			}
			if ( 'set_meal' !== Ingredient::get_type_slug( $set_meal_id ) ) {
				return [];
			}
			$addons = self::sanitize_addons( $product_id, $raw['addons'] ?? [] );
			return [
				'mode'        => 'set',
				'set_meal_id' => $set_meal_id,
				'addons'      => $addons,
				'tier'        => $config['tier'],
			];
		}

		$protein_id = isset( $raw['protein_id'] ) ? (int) $raw['protein_id'] : 0;
		$carb_id    = isset( $raw['carb_id'] ) ? (int) $raw['carb_id'] : 0;
		$greens_ids = isset( $raw['greens_ids'] ) && is_array( $raw['greens_ids'] )
			? array_values( array_filter( array_map( 'intval', $raw['greens_ids'] ) ) )
			: [];
		$greens_ids = array_slice( array_unique( $greens_ids ), 0, 2 );

		if ( $protein_id && 'protein' !== Ingredient::get_type_slug( $protein_id ) ) {
			$protein_id = 0;
		}
		if ( $carb_id && 'carb' !== Ingredient::get_type_slug( $carb_id ) ) {
			$carb_id = 0;
		}
		foreach ( $greens_ids as $k => $id ) {
			if ( 'greens' !== Ingredient::get_type_slug( (int) $id ) ) {
				unset( $greens_ids[ $k ] );
			}
		}
		$greens_ids = array_values( $greens_ids );

		if ( ! empty( $config['allowed_proteins'] ) && $protein_id && ! in_array( $protein_id, $config['allowed_proteins'], true ) ) {
			$protein_id = 0;
		}
		if ( ! empty( $config['allowed_carbs'] ) && $carb_id && ! in_array( $carb_id, $config['allowed_carbs'], true ) ) {
			$carb_id = 0;
		}
		if ( ! empty( $config['allowed_greens'] ) ) {
			$greens_ids = array_values( array_intersect( $greens_ids, $config['allowed_greens'] ) );
		}

		if ( count( $greens_ids ) === 2 ) {
			$carb_id = 0;
		}

		$addons = self::sanitize_addons( $product_id, $raw['addons'] ?? [] );

		return [
			'mode'       => 'build',
			'protein_id' => $protein_id,
			'carb_id'    => $carb_id ?: null,
			'greens_ids' => $greens_ids,
			'addons'     => $addons,
			'tier'       => $config['tier'],
		];
	}

	/**
	 * Normalise a standalone product selection: a single item chosen from the
	 * product's configured allow-list. When the product offers exactly one item
	 * it is auto-selected, so the customer needs no on-page choice.
	 */
	private static function normalize_standalone( int $product_id, array $raw ): array {
		$cfg = StandaloneProduct::get_config( $product_id );
		if ( ! $cfg['enabled'] || empty( $cfg['ids'] ) ) {
			return [];
		}

		$item_id = isset( $raw['item_id'] ) ? (int) $raw['item_id'] : 0;
		// Single-item products are fixed: accept the lone item even if the client
		// didn't echo it back.
		if ( ! $item_id && 1 === count( $cfg['ids'] ) ) {
			$item_id = (int) $cfg['ids'][0];
		}
		if ( ! $item_id || ! in_array( $item_id, $cfg['ids'], true ) ) {
			return [];
		}
		// The chosen item must really be of the configured type.
		if ( $cfg['type'] !== Ingredient::get_type_slug( $item_id ) ) {
			return [];
		}

		return [
			'mode'      => 'standalone',
			'item_id'   => $item_id,
			'item_type' => $cfg['type'],
			'addons'    => self::sanitize_addons( $product_id, $raw['addons'] ?? [] ),
			'tier'      => (string) ( get_post_meta( $product_id, '_fn_meal_tier', true ) ?: 'standard' ),
		];
	}

	private static function sanitize_addons( int $product_id, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$available = [];
		foreach ( AddOnMeta::get_addons( $product_id ) as $row ) {
			$available[ (string) ( $row['id'] ?? '' ) ] = $row;
		}
		$chosen = [];
		foreach ( $raw as $entry ) {
			$id = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : (string) $entry;
			if ( isset( $available[ $id ] ) ) {
				$chosen[] = [
					'id'        => $available[ $id ]['id'],
					'label'     => (string) $available[ $id ]['label'],
					'price'     => (float) $available[ $id ]['price'],
					'kcal'      => (float) ( $available[ $id ]['kcal'] ?? 0 ),
					'protein_g' => (float) ( $available[ $id ]['protein_g'] ?? 0 ),
					'carbs_g'   => (float) ( $available[ $id ]['carbs_g'] ?? 0 ),
					'fat_g'     => (float) ( $available[ $id ]['fat_g'] ?? 0 ),
				];
			}
		}
		return $chosen;
	}

	/**
	 * Price delta for a selection (ingredient swaps + add-ons), added to the
	 * product's catalog/bundle base. Thin wrapper around the central interpreter
	 * so MealPricing and the online/offline carts share one definition.
	 */
	public static function compute_price_delta( int $product_id, array $selection ): float {
		unset( $product_id );
		return Selection::price_delta( $selection );
	}
}

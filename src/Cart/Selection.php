<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

use FastNutrition\MealPrep\PostTypes\IngredientCatalog;

/**
 * Central interpreter for a normalised `_fn_selection` array.
 *
 * Every part of the plugin that needs to READ a selection — cart display,
 * order line meta, macros, price deltas, the prep cache, the prep sheet and
 * the labels — routes through this one class. The meaning of a selection
 * therefore lives in a single place, and a new selection kind only needs
 * handling here rather than in a dozen scattered switch statements.
 *
 * Supported modes:
 *   - 'build'      : protein + (carb | 2× greens) + greens   — the meal builder.
 *   - 'set'        : a single set-meal ingredient            — the meal builder.
 *   - 'standalone' : a single ingredient of a chosen type    — a standalone product.
 *   - 'sweet'      : LEGACY. A single sweet ingredient, the old meal-builder
 *                    "Sweet mode". Read-only — kept so historical orders placed
 *                    before sweets were decoupled still render everywhere.
 *
 * A 'standalone' selection carries:
 *   [ 'mode' => 'standalone', 'item_id' => int, 'item_type' => string, 'addons' => [], 'tier' => string ]
 * where item_type is the IngredientType slug it was drawn from (e.g. 'set_meal' or 'sweet').
 */
final class Selection {

	/**
	 * The ingredient post IDs that make up this selection's food composition,
	 * in display order.
	 *
	 * Pure array logic (no WordPress calls) so it is unit-testable, and it is
	 * the single basis for macros, price deltas, the prep cache and prep-sheet
	 * totals — every "which ingredients does this meal use" question.
	 *
	 * @param array $sel A normalised selection.
	 * @return int[]
	 */
	public static function ingredient_ids( array $sel ): array {
		switch ( (string) ( $sel['mode'] ?? '' ) ) {
			case 'standalone':
				$id = (int) ( $sel['item_id'] ?? 0 );
				return $id ? [ $id ] : [];
			case 'set':
				$id = (int) ( $sel['set_meal_id'] ?? 0 );
				return $id ? [ $id ] : [];
			case 'sweet': // Legacy.
				$id = (int) ( $sel['sweet_id'] ?? 0 );
				return $id ? [ $id ] : [];
			default: // 'build' (and any unknown mode falls back to build keys).
				$ids = [];
				if ( ! empty( $sel['protein_id'] ) ) {
					$ids[] = (int) $sel['protein_id'];
				}
				if ( ! empty( $sel['carb_id'] ) ) {
					$ids[] = (int) $sel['carb_id'];
				}
				foreach ( (array) ( $sel['greens_ids'] ?? [] ) as $gid ) {
					if ( (int) $gid ) {
						$ids[] = (int) $gid;
					}
				}
				return $ids;
		}
	}

	/** True when the selection is a single named item (set meal, standalone or legacy sweet). */
	public static function is_single_item( array $sel ): bool {
		return in_array( (string) ( $sel['mode'] ?? '' ), [ 'standalone', 'set', 'sweet' ], true );
	}

	/**
	 * True when the selection is a sweet — either a standalone product of type
	 * 'sweet' or a legacy 'sweet'-mode line. Used to give sweet labels their own
	 * treatment (blank USE BY, no storage/reheat text).
	 */
	public static function is_sweet( array $sel ): bool {
		$mode = (string) ( $sel['mode'] ?? '' );
		if ( 'sweet' === $mode ) {
			return true; // Legacy.
		}
		return 'standalone' === $mode && 'sweet' === (string) ( $sel['item_type'] ?? '' );
	}

	/**
	 * Human-readable description of the meal composition, e.g. "Chicken + Rice +
	 * Broccoli" or "Peanut Butter Protein Flapjack". Used by the prep dashboard,
	 * prep sheet and meal labels.
	 */
	public static function describe( array $sel ): string {
		$ids = self::ingredient_ids( $sel );
		if ( self::is_single_item( $sel ) ) {
			return $ids ? (string) get_the_title( $ids[0] ) : '';
		}
		$parts = array_filter( array_map( static fn( $id ) => (string) get_the_title( (int) $id ), $ids ) );
		return implode( ' + ', $parts );
	}

	/**
	 * Ordered label => value composition pairs, EXCLUDING add-ons. Shared by the
	 * cart line display and the order line meta so the two never diverge.
	 *
	 * @return array<int,array{key:string,value:string}>
	 */
	public static function composition_pairs( array $sel ): array {
		$mode = (string) ( $sel['mode'] ?? '' );

		if ( self::is_single_item( $sel ) ) {
			$ids = self::ingredient_ids( $sel );
			if ( ! $ids ) {
				return [];
			}
			$label = 'standalone' === $mode
				? self::type_label( (string) ( $sel['item_type'] ?? '' ) )
				: ( 'set' === $mode ? __( 'Set Meal', 'fastnutrition-mealprep' ) : __( 'Sweet', 'fastnutrition-mealprep' ) );
			return [ [ 'key' => $label, 'value' => (string) get_the_title( $ids[0] ) ] ];
		}

		$pairs = [];
		if ( ! empty( $sel['protein_id'] ) ) {
			$pairs[] = [ 'key' => __( 'Protein', 'fastnutrition-mealprep' ), 'value' => (string) get_the_title( (int) $sel['protein_id'] ) ];
		}
		if ( ! empty( $sel['carb_id'] ) ) {
			$pairs[] = [ 'key' => __( 'Carb', 'fastnutrition-mealprep' ), 'value' => (string) get_the_title( (int) $sel['carb_id'] ) ];
		}
		if ( ! empty( $sel['greens_ids'] ) && is_array( $sel['greens_ids'] ) ) {
			$names = array_filter( array_map( static fn( $id ) => (string) get_the_title( (int) $id ), $sel['greens_ids'] ) );
			if ( ! empty( $names ) ) {
				$pairs[] = [
					'key'   => 2 === count( $names ) ? __( 'Greens (2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' ),
					'value' => implode( ' + ', $names ),
				];
			}
		}
		return $pairs;
	}

	/**
	 * Comma-separated add-on summary, e.g. "2 Boiled Eggs (+£1.00), Sauce".
	 * Returns '' when there are no add-ons. Shared by cart + order meta.
	 */
	public static function addons_summary( array $sel ): string {
		if ( empty( $sel['addons'] ) || ! is_array( $sel['addons'] ) ) {
			return '';
		}
		$parts = [];
		foreach ( $sel['addons'] as $addon ) {
			$label = isset( $addon['label'] ) ? (string) $addon['label'] : '';
			if ( '' === $label ) {
				continue;
			}
			$price   = isset( $addon['price'] ) ? (float) $addon['price'] : 0;
			$parts[] = $price > 0
				? sprintf( '%s (+%s)', $label, wp_strip_all_tags( wc_price( $price ) ) )
				: $label;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Add-on label => count for a single selection, e.g. [ '2 Boiled Eggs' => 1 ].
	 * Keyed by the human label (not the product-scoped add-on id) so the same
	 * add-on aggregates across different products on the prep sheet, regardless
	 * of which route (builder, standalone, sweet, quick order) the order took.
	 *
	 * @return array<string,int>
	 */
	public static function addon_counts( array $sel ): array {
		if ( empty( $sel['addons'] ) || ! is_array( $sel['addons'] ) ) {
			return [];
		}
		$counts = [];
		foreach ( $sel['addons'] as $addon ) {
			$label = isset( $addon['label'] ) ? trim( (string) $addon['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Total price delta for a selection: the sum of every composition
	 * ingredient's `_fn_price_delta` plus every chosen add-on's price. This is
	 * added to the product's catalog/bundle base in MealPricing.
	 */
	public static function price_delta( array $sel ): float {
		$delta = 0.0;
		foreach ( self::ingredient_ids( $sel ) as $id ) {
			// Cached catalogue on the hot path; direct read for ids not published.
			$cached = IngredientCatalog::price_delta( $id );
			$delta += null !== $cached ? $cached : (float) get_post_meta( $id, '_fn_price_delta', true );
		}
		foreach ( (array) ( $sel['addons'] ?? [] ) as $addon ) {
			$delta += (float) ( $addon['price'] ?? 0 );
		}
		return $delta;
	}

	/**
	 * A canonical signature for a BUILD combination (protein + carb + greens),
	 * with greens order-normalised and add-ons ignored — so {A,B} == {B,A} and the
	 * same composition always hashes identically. Returns null for set-meal and
	 * standalone selections (they aren't customer-built "combinations").
	 */
	public static function combo_signature( array $sel ): ?string {
		if ( 'build' !== ( $sel['mode'] ?? '' ) ) {
			return null;
		}
		if ( (int) ( $sel['protein_id'] ?? 0 ) <= 0 ) {
			return null;
		}
		$comp = self::combo_composition( $sel );
		return 'b:' . $comp['protein_id'] . ':' . $comp['carb_id'] . ':' . implode( ',', $comp['greens_ids'] );
	}

	/**
	 * The order-normalised composition of a build selection:
	 * { protein_id, carb_id, greens_ids[] } with greens sorted. Add-ons excluded.
	 *
	 * @return array{protein_id:int,carb_id:int,greens_ids:int[]}
	 */
	public static function combo_composition( array $sel ): array {
		$greens = array_values( array_filter( array_map( 'intval', (array) ( $sel['greens_ids'] ?? [] ) ) ) );
		sort( $greens );
		return [
			'protein_id' => (int) ( $sel['protein_id'] ?? 0 ),
			'carb_id'    => (int) ( $sel['carb_id'] ?? 0 ),
			'greens_ids' => $greens,
		];
	}

	/**
	 * Map an IngredientType slug to its human label. Used to title a standalone
	 * item on the cart line and order meta ("Set Meal", "Sweet", …).
	 */
	public static function type_label( string $slug ): string {
		$map = [
			'protein'  => __( 'Protein', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carb', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meal', 'fastnutrition-mealprep' ),
			'sweet'    => __( 'Sweet', 'fastnutrition-mealprep' ),
		];
		return $map[ $slug ] ?? __( 'Item', 'fastnutrition-mealprep' );
	}
}

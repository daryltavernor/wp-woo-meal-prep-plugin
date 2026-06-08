<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\PostTypes;

/**
 * Cached catalogue of every published ingredient's pricing + macro data, so the
 * cart/checkout don't re-read each ingredient's post meta from the database on
 * every Store API request.
 *
 * The cart hot path needs two values per ingredient on every request:
 *   - `_fn_price_delta` — summed by BundlePricer on EVERY calculate_totals(), and
 *   - `_fn_macros`      — summed for the running macro total in the cart response.
 *
 * With no persistent object cache (Redis is intentionally off), each of those was
 * a per-ingredient meta read on every cart/checkout load. This builds the whole
 * catalogue once (two queries) and caches it, so a typical cart load does ONE
 * read instead of ~one per distinct ingredient — then a per-request memo makes
 * every repeat lookup free.
 *
 * Invalidation is event-driven and exact (an ingredient being saved, trashed or
 * deleted clears the cache), so prices and macros can never go stale. Any id not
 * in the published catalogue (drafts, an item trashed mid-request) falls back to
 * a direct meta read, preserving the previous behaviour exactly.
 */
final class IngredientCatalog {

	private const TRANSIENT = 'fn_ingredient_catalog';

	/** Per-request memo so repeated lookups within one request cost nothing. */
	private static ?array $memo = null;

	public function register(): void {
		add_action( 'save_post_' . Ingredient::POST_TYPE, [ __CLASS__, 'flush' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'flush_for_post' ] );
		add_action( 'trashed_post', [ __CLASS__, 'flush_for_post' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'flush_for_post' ] );
	}

	/** Macros for an ingredient, or null when it isn't in the published catalogue. */
	public static function macros( int $id ): ?array {
		$all = self::all();
		return $all[ $id ]['macros'] ?? null;
	}

	/** Price delta for an ingredient, or null when it isn't in the published catalogue. */
	public static function price_delta( int $id ): ?float {
		$all = self::all();
		return isset( $all[ $id ] ) ? (float) $all[ $id ]['price_delta'] : null;
	}

	/**
	 * @return array<int,array{macros:array{kcal:float,protein_g:float,carbs_g:float,fat_g:float},price_delta:float}>
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			self::$memo = $cached;
			return $cached;
		}
		$map = self::build();
		// Event-driven invalidation keeps this exact; the TTL is only a backstop.
		set_transient( self::TRANSIENT, $map, DAY_IN_SECONDS );
		self::$memo = $map;
		return $map;
	}

	public static function flush(): void {
		self::$memo = null;
		delete_transient( self::TRANSIENT );
	}

	/** Flush only when the affected post is an ingredient (these hooks fire for all post types). */
	public static function flush_for_post( $post_id ): void {
		if ( Ingredient::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::flush();
		}
	}

	/**
	 * @return array<int,array{macros:array{kcal:float,protein_g:float,carbs_g:float,fat_g:float},price_delta:float}>
	 */
	private static function build(): array {
		$ids = get_posts(
			[
				'post_type'      => Ingredient::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		if ( empty( $ids ) ) {
			return [];
		}
		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids ); // Prime all ingredient meta in a single query.

		$map = [];
		foreach ( $ids as $id ) {
			$macros     = get_post_meta( $id, '_fn_macros', true );
			$macros     = is_array( $macros ) ? $macros : [];
			$map[ $id ] = [
				'macros'      => [
					'kcal'      => (float) ( $macros['kcal'] ?? 0 ),
					'protein_g' => (float) ( $macros['protein_g'] ?? 0 ),
					'carbs_g'   => (float) ( $macros['carbs_g'] ?? 0 ),
					'fat_g'     => (float) ( $macros['fat_g'] ?? 0 ),
				],
				'price_delta' => (float) get_post_meta( $id, '_fn_price_delta', true ),
			];
		}
		return $map;
	}
}

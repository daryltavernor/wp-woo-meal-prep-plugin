<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Stats\PopularCombos;

/**
 * Read-only admin view of the current "Popular Combinations" stats, plus a
 * "Recompute now" button (queues a background Action Scheduler run). The list
 * itself is produced weekly by Stats\PopularCombos — this screen just surfaces
 * it so staff can see what customers will be offered.
 */
final class PopularCombosAdmin {

	public const PAGE_SLUG = 'fn-popular-combos';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_recompute' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function maybe_recompute(): void {
		if ( ! isset( $_POST['fn_recompute_combos_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_recompute_combos_nonce'] ) ), 'fn_recompute_combos' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		PopularCombos::queue_recompute();
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'fn_queued' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$data    = PopularCombos::get_results();
		$combos  = $data['combos'];
		$ingreds = $data['ingredients'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Popular Combinations', 'fastnutrition-mealprep' ) . '</h1>';

		if ( isset( $_GET['fn_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recompute queued — it runs in the background and the list updates shortly.', 'fastnutrition-mealprep' ) . '</p></div>';
		}

		$generated = (int) ( $data['generated'] ?? 0 );
		echo '<p>';
		if ( $generated > 0 ) {
			printf(
				/* translators: 1: window in days, 2: human-readable "time ago" */
				esc_html__( 'Top combinations over the last %1$d days, last calculated %2$s ago. Updated automatically once a week.', 'fastnutrition-mealprep' ),
				(int) ( $data['window_days'] ?? PopularCombos::WINDOW_DAYS ),
				esc_html( human_time_diff( $generated ) )
			);
		} else {
			esc_html_e( 'No stats yet — click "Recompute now" to build the first list from your recent orders.', 'fastnutrition-mealprep' );
		}
		echo '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'fn_recompute_combos', 'fn_recompute_combos_nonce' );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Recompute now', 'fastnutrition-mealprep' ) . '</button></p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Top combinations', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $combos ) ) {
			echo '<p><em>' . esc_html__( 'Nothing to show yet.', 'fastnutrition-mealprep' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
			echo '<th>#</th><th>' . esc_html__( 'Combination', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Sold (qty)', 'fastnutrition-mealprep' ) . '</th>';
			echo '</tr></thead><tbody>';
			$rank = 0;
			foreach ( $combos as $combo ) {
				$rank++;
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$d</td></tr>',
					$rank,
					esc_html( self::label_for( $combo ) ),
					(int) ( $combo['count'] ?? 0 )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Top ingredients', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $ingreds ) ) {
			echo '<p><em>' . esc_html__( 'Nothing to show yet.', 'fastnutrition-mealprep' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:480px"><thead><tr>';
			echo '<th>#</th><th>' . esc_html__( 'Ingredient', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Sold (qty)', 'fastnutrition-mealprep' ) . '</th>';
			echo '</tr></thead><tbody>';
			$rank = 0;
			foreach ( $ingreds as $row ) {
				$rank++;
				$title = get_the_title( (int) ( $row['id'] ?? 0 ) );
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$d</td></tr>',
					$rank,
					esc_html( '' !== $title ? $title : '#' . (int) ( $row['id'] ?? 0 ) ),
					(int) ( $row['count'] ?? 0 )
				);
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/** "Chicken + Rice + Broccoli & Spinach" from a stored combo composition. */
	private static function label_for( array $combo ): string {
		$parts = [];
		$protein = get_the_title( (int) ( $combo['protein_id'] ?? 0 ) );
		if ( '' !== $protein ) {
			$parts[] = $protein;
		}
		$carb = (int) ( $combo['carb_id'] ?? 0 );
		if ( $carb > 0 && '' !== get_the_title( $carb ) ) {
			$parts[] = get_the_title( $carb );
		}
		$greens = [];
		foreach ( (array) ( $combo['greens_ids'] ?? [] ) as $gid ) {
			$name = get_the_title( (int) $gid );
			if ( '' !== $name ) {
				$greens[] = $name;
			}
		}
		if ( $greens ) {
			$parts[] = implode( ' & ', $greens );
		}
		return implode( ' + ', $parts );
	}
}

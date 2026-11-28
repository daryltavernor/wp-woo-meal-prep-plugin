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
		// Run it here and now rather than queueing: the scan takes about a
		// second, and a queued run only happens when WP-Cron gets round to it,
		// which on some hosts is "eventually". Staff clicked because they want
		// the list updated before they look at it.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		PopularCombos::ensure_scheduled();
		PopularCombos::recompute();
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'fn_done' => '1' ], admin_url( 'admin.php' ) ) );
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

		if ( isset( $_GET['fn_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recomputed from your recent orders.', 'fastnutrition-mealprep' ) . '</p></div>';
		}

		// Make sure the weekly schedule exists every time someone looks here.
		PopularCombos::ensure_scheduled();
		$pending  = PopularCombos::pending();
		$last_run = get_option( PopularCombos::OPTION_LAST_RUN );
		$last_run = is_array( $last_run ) ? $last_run : [];

		// WP-Cron health: Action Scheduler's own runner is due every minute, so
		// a runner that is a quarter of an hour late means background jobs on
		// this site are not firing at all.
		$runner_due = wp_next_scheduled( 'action_scheduler_run_queue' );
		if ( $runner_due && $runner_due < time() - 15 * MINUTE_IN_SECONDS ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Background jobs are running late.', 'fastnutrition-mealprep' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: %s: human-readable "time ago" */
					__( 'The WooCommerce scheduled-actions runner was due %s ago and has not run, which usually means WP-Cron is blocked on this site (Tools → Cron Events shows the error). Until that is fixed the weekly recompute only runs when WP-Cron is triggered; the button below runs it immediately.', 'fastnutrition-mealprep' ),
					human_time_diff( $runner_due )
				)
			);
			echo '</p></div>';
		}

		$generated = (int) ( $data['generated'] ?? 0 );
		echo '<p>';
		if ( $generated > 0 ) {
			printf(
				/* translators: 1: window in days, 2: human-readable "time ago" */
				esc_html__( 'Top combinations over the last %1$d days, last calculated %2$s ago.', 'fastnutrition-mealprep' ),
				(int) ( $data['window_days'] ?? PopularCombos::WINDOW_DAYS ),
				esc_html( human_time_diff( $generated ) )
			);
			if ( ! empty( $last_run['context'] ) ) {
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: how it ran (cron/admin), 2: seconds, 3: number of orders */
						__( '(last run: %1$s, %2$ss over %3$d orders)', 'fastnutrition-mealprep' ),
						'cron' === $last_run['context'] ? __( 'automatic', 'fastnutrition-mealprep' ) : __( 'manual', 'fastnutrition-mealprep' ),
						(string) ( $last_run['seconds'] ?? '?' ),
						(int) ( $last_run['orders'] ?? 0 )
					)
				);
			}
		} else {
			esc_html_e( 'No stats yet — click "Recompute now" to build the first list from your recent orders.', 'fastnutrition-mealprep' );
		}
		echo '</p><p>';
		if ( false !== $pending['next_recurring'] ) {
			printf(
				/* translators: 1: date and time, 2: human-readable "in x" */
				esc_html__( 'Next automatic recompute: %1$s (in %2$s), as a WooCommerce scheduled action, then weekly.', 'fastnutrition-mealprep' ),
				esc_html( wp_date( 'D j M, H:i', (int) $pending['next_recurring'] ) ),
				esc_html( human_time_diff( (int) $pending['next_recurring'] ) )
			);
		} else {
			esc_html_e( 'Next automatic recompute: not scheduled — it is re-created automatically the next time an admin page loads.', 'fastnutrition-mealprep' );
		}
		if ( $pending['one_off'] > 0 ) {
			echo ' ' . esc_html( sprintf( /* translators: %d: count */ _n( '%d queued recompute is waiting for the background runner.', '%d queued recomputes are waiting for the background runner.', $pending['one_off'], 'fastnutrition-mealprep' ), $pending['one_off'] ) );
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

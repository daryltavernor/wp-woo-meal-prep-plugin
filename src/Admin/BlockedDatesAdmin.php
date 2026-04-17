<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\BlockedDates;

final class BlockedDatesAdmin {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function handle_actions(): void {
		if ( ! isset( $_POST['fn_blocked_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_blocked_nonce'] ) ), 'fn_blocked_action' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['fn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_action'] ) ) : '';
		if ( 'add' === $action ) {
			$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : '';
			$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				BlockedDates::add( $date, $reason );
			}
		} elseif ( 'remove' === $action && ! empty( $_POST['id'] ) ) {
			BlockedDates::remove( (int) $_POST['id'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fn-blocked-dates' ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Blocked Dates', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<p>' . esc_html__( 'Blocked dates prevent all delivery and collection slots from being offered.', 'fastnutrition-mealprep' ) . '</p>';

		echo '<form method="post" style="margin:1em 0;">';
		wp_nonce_field( 'fn_blocked_action', 'fn_blocked_nonce' );
		echo '<input type="hidden" name="fn_action" value="add" />';
		printf( '<label>%s <input type="date" name="date" required /></label> ', esc_html__( 'Date', 'fastnutrition-mealprep' ) );
		printf( '<label>%s <input type="text" name="reason" class="regular-text" /></label> ', esc_html__( 'Reason (optional)', 'fastnutrition-mealprep' ) );
		submit_button( __( 'Block date', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo '</form>';

		$rows = BlockedDates::all();
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No blocked dates.', 'fastnutrition-mealprep' ) . '</em></td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['blocked_date'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</td>';
			echo '<td><form method="post">';
			wp_nonce_field( 'fn_blocked_action', 'fn_blocked_nonce' );
			echo '<input type="hidden" name="fn_action" value="remove" />';
			echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '" />';
			echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Remove', 'fastnutrition-mealprep' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

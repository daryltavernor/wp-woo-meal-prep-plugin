<?php
/**
 * Simple CRUD for blocked dates.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\BlockedDates;
use FastNutrition\MealPrep\Support\Security;

final class BlockedDatesAdmin {

	public function register(): void {
		add_action( 'admin_post_fn_add_blocked_date', [ $this, 'handle_add' ] );
		add_action( 'admin_post_fn_delete_blocked_date', [ $this, 'handle_delete' ] );
	}

	public function render(): void {
		Security::require_manage();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Blocked Dates', 'fastnutrition-mealprep' ); ?></h1>
			<p><?php esc_html_e( 'Dates added here will prevent all deliveries and collections on that day, regardless of profile.', 'fastnutrition-mealprep' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 24px;">
				<input type="hidden" name="action" value="fn_add_blocked_date">
				<?php wp_nonce_field( 'fn_add_blocked_date' ); ?>
				<input type="date" name="blocked_date" required>
				<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'fastnutrition-mealprep' ); ?>" class="regular-text">
				<button class="button button-primary"><?php esc_html_e( 'Add blocked date', 'fastnutrition-mealprep' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Date', 'fastnutrition-mealprep' ); ?></th><th><?php esc_html_e( 'Reason', 'fastnutrition-mealprep' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( BlockedDates::all() as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['blocked_date'] ); ?></td>
						<td><?php echo esc_html( $row['reason'] ); ?></td>
						<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fn_delete_blocked_date&id=' . $row['id'] ), 'fn_delete_blocked_date_' . $row['id'] ) ); ?>" onclick="return confirm('Delete?');"><?php esc_html_e( 'Remove', 'fastnutrition-mealprep' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_add(): void {
		Security::require_manage();
		check_admin_referer( 'fn_add_blocked_date' );
		$date   = isset( $_POST['blocked_date'] ) ? sanitize_text_field( wp_unslash( $_POST['blocked_date'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		BlockedDates::add( $date, $reason );
		wp_safe_redirect( admin_url( 'admin.php?page=fn-blocked-dates' ) );
		exit;
	}

	public function handle_delete(): void {
		Security::require_manage();
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'fn_delete_blocked_date_' . $id );
		BlockedDates::remove( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=fn-blocked-dates' ) );
		exit;
	}
}

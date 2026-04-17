<?php
/**
 * CRUD UI for delivery / collection profiles.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Support\Security;

final class ProfileAdmin {

	public function register(): void {
		add_action( 'admin_post_fn_save_profile', [ $this, 'handle_save' ] );
		add_action( 'admin_post_fn_delete_profile', [ $this, 'handle_delete' ] );
	}

	public function render(): void {
		Security::require_manage();
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$profile = $edit_id ? Profile::find( $edit_id ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Delivery & Collection Profiles', 'fastnutrition-mealprep' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fn-profiles&edit=0' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'fastnutrition-mealprep' ); ?></a>
			</h1>

			<?php if ( isset( $_GET['edit'] ) ) : ?>
				<?php $this->render_form( $profile ); ?>
			<?php else : ?>
				<?php $this->render_list(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_list(): void {
		$profiles = Profile::all();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Days', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Slots', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Postcodes', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Active', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Actions', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
		foreach ( $profiles as $p ) {
			$days = [];
			foreach ( Profile::all_days() as $flag => $label ) {
				if ( $p['days_mask'] & $flag ) {
					$days[] = substr( $label, 0, 3 );
				}
			}
			$slots = array_map( static fn( $s ) => $s['start'] . '–' . $s['end'] . ( isset( $s['capacity'] ) ? ' (cap ' . $s['capacity'] . ')' : '' ), $p['slots'] );
			echo '<tr>';
			echo '<td>' . esc_html( $p['name'] ) . '</td>';
			echo '<td>' . esc_html( $p['method'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $days ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $slots ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_slice( $p['postcodes'], 0, 6 ) ) ) . ( count( $p['postcodes'] ) > 6 ? '…' : '' ) . '</td>';
			echo '<td>' . ( $p['active'] ? esc_html__( 'Yes', 'fastnutrition-mealprep' ) : esc_html__( 'No', 'fastnutrition-mealprep' ) ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=fn-profiles&edit=' . $p['id'] ) ) . '">' . esc_html__( 'Edit', 'fastnutrition-mealprep' ) . '</a> | <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fn_delete_profile&id=' . $p['id'] ), 'fn_delete_profile_' . $p['id'] ) ) . '" onclick="return confirm(\'Delete profile?\');">' . esc_html__( 'Delete', 'fastnutrition-mealprep' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_form( ?array $profile ): void {
		$profile ??= [ 'id' => 0, 'name' => '', 'method' => Profile::METHOD_DELIVERY, 'days_mask' => 0, 'slots' => [], 'postcodes' => [], 'active' => true, 'priority' => 10 ];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fn_save_profile">
			<input type="hidden" name="id" value="<?php echo (int) $profile['id']; ?>">
			<?php wp_nonce_field( 'fn_save_profile' ); ?>

			<table class="form-table">
				<tr><th><label for="name"><?php esc_html_e( 'Name', 'fastnutrition-mealprep' ); ?></label></th><td><input type="text" id="name" name="name" value="<?php echo esc_attr( $profile['name'] ); ?>" required class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'Method', 'fastnutrition-mealprep' ); ?></th>
					<td>
						<label><input type="radio" name="method" value="delivery" <?php checked( $profile['method'], 'delivery' ); ?>> <?php esc_html_e( 'Delivery', 'fastnutrition-mealprep' ); ?></label>
						<label><input type="radio" name="method" value="collection" <?php checked( $profile['method'], 'collection' ); ?>> <?php esc_html_e( 'Collection', 'fastnutrition-mealprep' ); ?></label>
					</td>
				</tr>
				<tr><th><?php esc_html_e( 'Days', 'fastnutrition-mealprep' ); ?></th>
					<td>
						<?php foreach ( Profile::all_days() as $flag => $label ) : ?>
							<label style="margin-right: 10px;"><input type="checkbox" name="days[]" value="<?php echo (int) $flag; ?>" <?php checked( (bool) ( $profile['days_mask'] & $flag ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr><th><?php esc_html_e( 'Postcodes', 'fastnutrition-mealprep' ); ?></th>
					<td>
						<textarea name="postcodes" rows="5" cols="50" class="large-text"><?php echo esc_textarea( implode( "\n", $profile['postcodes'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Use * as a wildcard suffix (e.g. ST10* matches any ST10 postcode).', 'fastnutrition-mealprep' ); ?></p>
					</td>
				</tr>
				<tr><th><?php esc_html_e( 'Slots', 'fastnutrition-mealprep' ); ?></th>
					<td>
						<table id="fn-slots">
							<thead><tr><th>Start</th><th>End</th><th>Capacity (optional)</th><th></th></tr></thead>
							<tbody>
							<?php
							$slot_rows = $profile['slots'] ?: [ [ 'start' => '', 'end' => '', 'capacity' => '' ] ];
							foreach ( $slot_rows as $i => $s ) :
								?>
								<tr>
									<td><input type="time" name="slots[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr( $s['start'] ); ?>"></td>
									<td><input type="time" name="slots[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr( $s['end'] ); ?>"></td>
									<td><input type="number" min="0" step="1" name="slots[<?php echo (int) $i; ?>][capacity]" value="<?php echo esc_attr( (string) ( $s['capacity'] ?? '' ) ); ?>"></td>
									<td><button type="button" class="button fn-remove-row">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button" id="fn-add-slot"><?php esc_html_e( 'Add slot', 'fastnutrition-mealprep' ); ?></button></p>
						<script>
						jQuery(function($){
							$('#fn-add-slot').on('click', function(){
								var i = $('#fn-slots tbody tr').length;
								$('#fn-slots tbody').append(
									'<tr><td><input type="time" name="slots['+i+'][start]"></td>' +
									'<td><input type="time" name="slots['+i+'][end]"></td>' +
									'<td><input type="number" min="0" step="1" name="slots['+i+'][capacity]"></td>' +
									'<td><button type="button" class="button fn-remove-row">&times;</button></td></tr>'
								);
							});
							$('#fn-slots').on('click', '.fn-remove-row', function(){ $(this).closest('tr').remove(); });
						});
						</script>
					</td>
				</tr>
				<tr><th><label for="priority"><?php esc_html_e( 'Priority', 'fastnutrition-mealprep' ); ?></label></th><td><input type="number" min="0" step="1" id="priority" name="priority" value="<?php echo (int) $profile['priority']; ?>"><p class="description"><?php esc_html_e( 'Lower numbers match first. Used when multiple profiles cover the same postcode.', 'fastnutrition-mealprep' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Active', 'fastnutrition-mealprep' ); ?></th><td><label><input type="checkbox" name="active" value="1" <?php checked( $profile['active'] ); ?>> <?php esc_html_e( 'Available for orders', 'fastnutrition-mealprep' ); ?></label></td></tr>
			</table>

			<?php submit_button( __( 'Save profile', 'fastnutrition-mealprep' ) ); ?>
		</form>
		<?php
	}

	public function handle_save(): void {
		Security::require_manage();
		check_admin_referer( 'fn_save_profile' );
		$days_mask = 0;
		foreach ( (array) ( $_POST['days'] ?? [] ) as $d ) {
			$days_mask |= (int) $d;
		}
		Profile::save(
			[
				'id'        => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
				'name'      => (string) ( $_POST['name'] ?? '' ),
				'method'    => (string) ( $_POST['method'] ?? 'delivery' ),
				'days_mask' => $days_mask,
				'postcodes' => array_filter( array_map( 'trim', explode( "\n", (string) ( $_POST['postcodes'] ?? '' ) ) ) ),
				'slots'     => (array) ( $_POST['slots'] ?? [] ),
				'priority'  => (int) ( $_POST['priority'] ?? 10 ),
				'active'    => ! empty( $_POST['active'] ),
			]
		);
		wp_safe_redirect( admin_url( 'admin.php?page=fn-profiles&saved=1' ) );
		exit;
	}

	public function handle_delete(): void {
		Security::require_manage();
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'fn_delete_profile_' . $id );
		Profile::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=fn-profiles&deleted=1' ) );
		exit;
	}
}

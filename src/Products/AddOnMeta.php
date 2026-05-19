<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use WP_Post;

final class AddOnMeta {

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save' ], 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'fn_product_addons',
			__( 'Meal Add-ons', 'fastnutrition-mealprep' ),
			[ $this, 'render' ],
			'product',
			'normal',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'fn_save_addons', 'fn_addons_nonce' );
		$addons = self::get_addons( $post->ID );
		echo '<div style="background:#f7f7f7;padding:8px 12px;margin:0 0 10px;border-left:3px solid #2271b1">';
		echo '<strong>' . esc_html__( 'What add-ons are', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Optional extras the customer can tick when adding this product to cart (e.g. "+£1 boiled eggs"). Each tick adds the price to that line. Add-ons attach per line item, so the same product can appear in the cart twice with different add-ons. Leave the macro columns blank if the add-on shouldn\'t change the live macro readout.', 'fastnutrition-mealprep' );
		echo '</div>';
		echo '<table class="widefat" id="fn-addons-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Price (£)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:70px" title="' . esc_attr__( 'Optional', 'fastnutrition-mealprep' ) . '">' . esc_html__( 'kcal', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:70px" title="' . esc_attr__( 'Optional', 'fastnutrition-mealprep' ) . '">' . esc_html__( 'Protein (g)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:70px" title="' . esc_attr__( 'Optional', 'fastnutrition-mealprep' ) . '">' . esc_html__( 'Carbs (g)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th style="width:70px" title="' . esc_attr__( 'Optional', 'fastnutrition-mealprep' ) . '">' . esc_html__( 'Fat (g)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( empty( $addons ) ) {
			$addons = [ [ 'label' => '', 'price' => 0 ] ];
		}
		foreach ( $addons as $i => $row ) {
			echo '<tr>';
			printf( '<td><input type="text" name="fn_addons[%1$d][label]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['label'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.01" min="0" name="fn_addons[%1$d][price]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['price'] ?? 0 ) ) );
			printf( '<td><input type="number" step="0.1" min="0" name="fn_addons[%1$d][kcal]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['kcal'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.1" min="0" name="fn_addons[%1$d][protein_g]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['protein_g'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.1" min="0" name="fn_addons[%1$d][carbs_g]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['carbs_g'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.1" min="0" name="fn_addons[%1$d][fat_g]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['fat_g'] ?? '' ) ) );
			echo '<td><button type="button" class="button fn-addons-remove">&times;</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="fn-addons-add">' . esc_html__( 'Add row', 'fastnutrition-mealprep' ) . '</button></p>';
		?>
		<script>
		(function(){
			const add = document.getElementById('fn-addons-add');
			const tbody = document.querySelector('#fn-addons-table tbody');
			if (!add || !tbody) return;
			add.addEventListener('click', function () {
				const i = tbody.children.length;
				const tr = document.createElement('tr');
				tr.innerHTML =
					'<td><input type="text" name="fn_addons[' + i + '][label]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.01" min="0" name="fn_addons[' + i + '][price]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.1" min="0" name="fn_addons[' + i + '][kcal]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.1" min="0" name="fn_addons[' + i + '][protein_g]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.1" min="0" name="fn_addons[' + i + '][carbs_g]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.1" min="0" name="fn_addons[' + i + '][fat_g]" style="width:100%" /></td>' +
					'<td><button type="button" class="button fn-addons-remove">&times;</button></td>';
				tbody.appendChild(tr);
			});
			tbody.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fn-addons-remove')) {
					e.target.closest('tr').remove();
				}
			});
		})();
		</script>
		<?php
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['fn_addons_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_addons_nonce'] ) ), 'fn_save_addons' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw   = isset( $_POST['fn_addons'] ) && is_array( $_POST['fn_addons'] ) ? wp_unslash( $_POST['fn_addons'] ) : [];
		$rows  = [];
		$index = 0;
		foreach ( $raw as $row ) {
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$price = isset( $row['price'] ) ? max( 0, (float) $row['price'] ) : 0;
			if ( '' === $label ) {
				continue;
			}
			$rows[] = [
				'id'        => 'a' . ++$index,
				'label'     => $label,
				'price'     => $price,
				'kcal'      => isset( $row['kcal'] ) && '' !== $row['kcal'] ? max( 0, (float) $row['kcal'] ) : 0,
				'protein_g' => isset( $row['protein_g'] ) && '' !== $row['protein_g'] ? max( 0, (float) $row['protein_g'] ) : 0,
				'carbs_g'   => isset( $row['carbs_g'] ) && '' !== $row['carbs_g'] ? max( 0, (float) $row['carbs_g'] ) : 0,
				'fat_g'     => isset( $row['fat_g'] ) && '' !== $row['fat_g'] ? max( 0, (float) $row['fat_g'] ) : 0,
			];
		}
		update_post_meta( $post_id, '_fn_addons', $rows );
	}

	public static function get_addons( int $product_id ): array {
		$rows = get_post_meta( $product_id, '_fn_addons', true );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		// Backfill macro fields on rows saved before this feature shipped.
		foreach ( $rows as &$row ) {
			$row['kcal']      = (float) ( $row['kcal'] ?? 0 );
			$row['protein_g'] = (float) ( $row['protein_g'] ?? 0 );
			$row['carbs_g']   = (float) ( $row['carbs_g'] ?? 0 );
			$row['fat_g']     = (float) ( $row['fat_g'] ?? 0 );
			$row['price']     = (float) ( $row['price'] ?? 0 );
		}
		return array_values( $rows );
	}
}

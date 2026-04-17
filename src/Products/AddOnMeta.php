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
		echo '<p class="description">' . esc_html__( 'Optional extras attached per line item (e.g. "+£1 boiled eggs").', 'fastnutrition-mealprep' ) . '</p>';
		echo '<table class="widefat" id="fn-addons-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Price (£)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( empty( $addons ) ) {
			$addons = [ [ 'label' => '', 'price' => 0 ] ];
		}
		foreach ( $addons as $i => $row ) {
			echo '<tr>';
			printf( '<td><input type="text" name="fn_addons[%1$d][label]" value="%2$s" style="width:100%%" /></td>', (int) $i, esc_attr( (string) ( $row['label'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.01" min="0" name="fn_addons[%1$d][price]" value="%2$s" /></td>', (int) $i, esc_attr( (string) ( $row['price'] ?? 0 ) ) );
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
				tr.innerHTML = '<td><input type="text" name="fn_addons[' + i + '][label]" style="width:100%" /></td>' +
					'<td><input type="number" step="0.01" min="0" name="fn_addons[' + i + '][price]" /></td>' +
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
				'id'    => 'a' . ++$index,
				'label' => $label,
				'price' => $price,
			];
		}
		update_post_meta( $post_id, '_fn_addons', $rows );
	}

	public static function get_addons( int $product_id ): array {
		$rows = get_post_meta( $product_id, '_fn_addons', true );
		return is_array( $rows ) ? array_values( $rows ) : [];
	}
}

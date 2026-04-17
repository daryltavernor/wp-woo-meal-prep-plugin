<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

use WP_Post;

final class BundleMeta {

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save' ], 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'fn_product_bundles',
			__( 'Quantity Bundles', 'fastnutrition-mealprep' ),
			[ $this, 'render' ],
			'product',
			'normal',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'fn_save_bundles', 'fn_bundles_nonce' );
		$bundles = self::get_bundles( $post->ID );
		echo '<div style="background:#f7f7f7;padding:8px 12px;margin:0 0 10px;border-left:3px solid #2271b1">';
		echo '<strong>' . esc_html__( 'How bundle pricing works', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Bundles only apply when the customer has the specified quantity of THIS exact product in their cart. Desserts and other non-bundle items are never counted. The largest matching tier wins — e.g. if 10/£35 and 15/£50 both exist, adding 15 applies the £50 tier. Remaining qty (above a tier but below the next) stays at base price. The cart shows both the bundle total and an effective per-meal price (e.g. "10 for £35 (~£3.50 each)").', 'fastnutrition-mealprep' );
		echo '</div>';
		echo '<table class="widefat" id="fn-bundles-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Quantity', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Bundle price (£)', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( empty( $bundles ) ) {
			$bundles = [ [ 'qty' => '', 'price' => '' ] ];
		}
		foreach ( $bundles as $i => $row ) {
			echo '<tr>';
			printf( '<td><input type="number" min="2" name="fn_bundles[%1$d][qty]" value="%2$s" /></td>', (int) $i, esc_attr( (string) ( $row['qty'] ?? '' ) ) );
			printf( '<td><input type="number" step="0.01" min="0" name="fn_bundles[%1$d][price]" value="%2$s" /></td>', (int) $i, esc_attr( (string) ( $row['price'] ?? '' ) ) );
			echo '<td><button type="button" class="button fn-bundles-remove">&times;</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="fn-bundles-add">' . esc_html__( 'Add tier', 'fastnutrition-mealprep' ) . '</button></p>';
		?>
		<script>
		(function(){
			const add = document.getElementById('fn-bundles-add');
			const tbody = document.querySelector('#fn-bundles-table tbody');
			if (!add || !tbody) return;
			add.addEventListener('click', function () {
				const i = tbody.children.length;
				const tr = document.createElement('tr');
				tr.innerHTML = '<td><input type="number" min="2" name="fn_bundles[' + i + '][qty]" /></td>' +
					'<td><input type="number" step="0.01" min="0" name="fn_bundles[' + i + '][price]" /></td>' +
					'<td><button type="button" class="button fn-bundles-remove">&times;</button></td>';
				tbody.appendChild(tr);
			});
			tbody.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fn-bundles-remove')) {
					e.target.closest('tr').remove();
				}
			});
		})();
		</script>
		<?php
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['fn_bundles_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_bundles_nonce'] ) ), 'fn_save_bundles' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw  = isset( $_POST['fn_bundles'] ) && is_array( $_POST['fn_bundles'] ) ? wp_unslash( $_POST['fn_bundles'] ) : [];
		$rows = [];
		foreach ( $raw as $row ) {
			$qty   = isset( $row['qty'] ) ? (int) $row['qty'] : 0;
			$price = isset( $row['price'] ) ? (float) $row['price'] : 0;
			if ( $qty < 2 || $price <= 0 ) {
				continue;
			}
			$rows[] = [ 'qty' => $qty, 'price' => $price ];
		}
		usort( $rows, static fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
		update_post_meta( $post_id, '_fn_bundles', $rows );
	}

	public static function get_bundles( int $product_id ): array {
		$rows = get_post_meta( $product_id, '_fn_bundles', true );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		usort( $rows, static fn( $a, $b ) => (int) ( $b['qty'] ?? 0 ) <=> (int) ( $a['qty'] ?? 0 ) );
		return $rows;
	}
}

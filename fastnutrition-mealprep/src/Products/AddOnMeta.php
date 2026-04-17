<?php
/**
 * Per-product add-on definitions. Each add-on is { id, label, price, default }.
 * Persisted as JSON in _fn_addons meta on the product.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

final class AddOnMeta {

	public const META_KEY = '_fn_addons';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'tab' ], 22 );
		add_action( 'woocommerce_product_data_panels', [ $this, 'panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function tab( array $tabs ): array {
		$tabs['fn_addons'] = [
			'label'    => __( 'Add-ons', 'fastnutrition-mealprep' ),
			'target'   => 'fn_addons_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 22,
		];
		return $tabs;
	}

	public function panel(): void {
		global $post;
		$addons = self::get( (int) $post->ID );
		?>
		<div id="fn_addons_panel" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="description" style="padding: 0 12px;"><?php esc_html_e( 'Optional extras the customer can attach to each meal line. Prices are added to the line total.', 'fastnutrition-mealprep' ); ?></p>
				<table class="widefat" id="fn-addons-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Price (£)', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Default', 'fastnutrition-mealprep' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $addons as $index => $addon ) : ?>
							<tr>
								<td><input type="text" name="fn_addons[<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( $addon['label'] ); ?>" style="width: 100%;"></td>
								<td><input type="number" step="0.01" min="0" name="fn_addons[<?php echo (int) $index; ?>][price]" value="<?php echo esc_attr( (string) $addon['price'] ); ?>"></td>
								<td><input type="checkbox" name="fn_addons[<?php echo (int) $index; ?>][default]" value="1" <?php checked( ! empty( $addon['default'] ) ); ?>></td>
								<td><button type="button" class="button fn-remove-row">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="fn-addons-add"><?php esc_html_e( 'Add add-on', 'fastnutrition-mealprep' ); ?></button></p>
				<script>
				jQuery(function($){
					$('#fn-addons-add').on('click', function(){
						var i = $('#fn-addons-table tbody tr').length;
						$('#fn-addons-table tbody').append(
							'<tr><td><input type="text" name="fn_addons['+i+'][label]" style="width: 100%;"></td>' +
							'<td><input type="number" step="0.01" min="0" name="fn_addons['+i+'][price]"></td>' +
							'<td><input type="checkbox" name="fn_addons['+i+'][default]" value="1"></td>' +
							'<td><button type="button" class="button fn-remove-row">&times;</button></td></tr>'
						);
					});
					$('#fn-addons-table').on('click', '.fn-remove-row', function(){ $(this).closest('tr').remove(); });
				});
				</script>
			</div>
		</div>
		<?php
	}

	public function save( \WC_Product $product ): void {
		$raw  = isset( $_POST['fn_addons'] ) && is_array( $_POST['fn_addons'] ) ? wp_unslash( $_POST['fn_addons'] ) : [];
		$out  = [];
		foreach ( $raw as $i => $row ) {
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$out[] = [
				'id'      => 'ao_' . substr( md5( $label . $i ), 0, 8 ),
				'label'   => $label,
				'price'   => isset( $row['price'] ) ? max( 0, (float) $row['price'] ) : 0.0,
				'default' => ! empty( $row['default'] ),
			];
		}
		update_post_meta( $product->get_id(), self::META_KEY, $out );
	}

	/**
	 * @return array<int,array{id:string,label:string,price:float,default:bool}>
	 */
	public static function get( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : [];
	}
}

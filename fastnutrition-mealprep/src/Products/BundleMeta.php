<?php
/**
 * Per-product bundle tiers. Each tier is { qty, price }.
 * BundlePricer selects the largest tier that fits when calculating cart totals.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Products;

final class BundleMeta {

	public const META_KEY = '_fn_bundles';

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'tab' ], 23 );
		add_action( 'woocommerce_product_data_panels', [ $this, 'panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function tab( array $tabs ): array {
		$tabs['fn_bundles'] = [
			'label'    => __( 'Bundle Deals', 'fastnutrition-mealprep' ),
			'target'   => 'fn_bundles_panel',
			'class'    => [ 'show_if_simple' ],
			'priority' => 23,
		];
		return $tabs;
	}

	public function panel(): void {
		global $post;
		$tiers = self::get( (int) $post->ID );
		?>
		<div id="fn_bundles_panel" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="description" style="padding: 0 12px;"><?php esc_html_e( 'Quantity-based flat pricing that applies only to this product (e.g. "10 for £35"). The largest tier that fits is used; the remainder stays at the base price.', 'fastnutrition-mealprep' ); ?></p>
				<table class="widefat" id="fn-bundles-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Quantity', 'fastnutrition-mealprep' ); ?></th>
							<th><?php esc_html_e( 'Bundle price (£)', 'fastnutrition-mealprep' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tiers as $i => $tier ) : ?>
							<tr>
								<td><input type="number" min="2" step="1" name="fn_bundles[<?php echo (int) $i; ?>][qty]" value="<?php echo esc_attr( (string) $tier['qty'] ); ?>"></td>
								<td><input type="number" step="0.01" min="0" name="fn_bundles[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( (string) $tier['price'] ); ?>"></td>
								<td><button type="button" class="button fn-remove-row">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="fn-bundles-add"><?php esc_html_e( 'Add tier', 'fastnutrition-mealprep' ); ?></button></p>
				<script>
				jQuery(function($){
					$('#fn-bundles-add').on('click', function(){
						var i = $('#fn-bundles-table tbody tr').length;
						$('#fn-bundles-table tbody').append(
							'<tr><td><input type="number" min="2" step="1" name="fn_bundles['+i+'][qty]"></td>' +
							'<td><input type="number" step="0.01" min="0" name="fn_bundles['+i+'][price]"></td>' +
							'<td><button type="button" class="button fn-remove-row">&times;</button></td></tr>'
						);
					});
					$('#fn-bundles-table').on('click', '.fn-remove-row', function(){ $(this).closest('tr').remove(); });
				});
				</script>
			</div>
		</div>
		<?php
	}

	public function save( \WC_Product $product ): void {
		$raw = isset( $_POST['fn_bundles'] ) && is_array( $_POST['fn_bundles'] ) ? wp_unslash( $_POST['fn_bundles'] ) : [];
		$out = [];
		foreach ( $raw as $row ) {
			$qty   = isset( $row['qty'] ) ? max( 2, (int) $row['qty'] ) : 0;
			$price = isset( $row['price'] ) ? max( 0, (float) $row['price'] ) : 0.0;
			if ( 0 === $qty ) {
				continue;
			}
			$out[] = [ 'qty' => $qty, 'price' => $price ];
		}
		// Sort descending by qty so the pricer can scan largest-first.
		usort( $out, static fn( array $a, array $b ): int => $b['qty'] <=> $a['qty'] );
		update_post_meta( $product->get_id(), self::META_KEY, $out );
	}

	/**
	 * @return array<int,array{qty:int,price:float}> sorted descending by qty.
	 */
	public static function get( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		usort( $raw, static fn( array $a, array $b ): int => (int) $b['qty'] <=> (int) $a['qty'] );
		return array_map(
			static fn( array $r ): array => [ 'qty' => (int) $r['qty'], 'price' => (float) $r['price'] ],
			$raw
		);
	}

	public static function has_bundles( int $product_id ): bool {
		return ! empty( self::get( $product_id ) );
	}
}

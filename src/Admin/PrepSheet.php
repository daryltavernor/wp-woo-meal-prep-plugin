<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Cart\Selection;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\InStore\PrepOrderStatus;
use FastNutrition\MealPrep\PostTypes\Ingredient;

final class PrepSheet {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_pdf' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function maybe_handle_pdf(): void {
		if ( ! isset( $_GET['page'], $_GET['action'] ) || 'fn-prep-sheet' !== $_GET['page'] || 'pdf' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_prep_sheet_pdf' );

		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : gmdate( 'Y-m-d' );
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( (string) $_GET['method'] ) ) : '';

		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install.', 'fastnutrition-mealprep' ) );
		}

		ob_start();
		$this->render_sheet( $date, $method, true );
		$html = (string) ob_get_clean();

		$dompdf = new \Dompdf\Dompdf( [ 'isRemoteEnabled' => false ] );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$dompdf->stream( 'prep-sheet-' . $date . '.pdf', [ 'Attachment' => true ] );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( (string) $_GET['method'] ) ) : '';
		$pdf_url = wp_nonce_url(
			add_query_arg(
				[ 'page' => 'fn-prep-sheet', 'action' => 'pdf', 'date' => $date, 'method' => $method ],
				admin_url( 'admin.php' )
			),
			'fn_prep_sheet_pdf'
		);

		echo '<div class="wrap fn-prep-sheet-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Prep Sheet', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<div class="fn-no-print" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:900px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page is for', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'A print-ready version of the kitchen prep list. Three sections: ingredient totals for mise-en-place, a per-order pick list with tick-off boxes for packing, and a delivery run sheet grouped by profile/postcode for drivers. Use "Print" for your browser print dialog or "Download PDF" for an A4 PDF.', 'fastnutrition-mealprep' );
		echo '</p></div>';
		echo '<form method="get" class="fn-no-print" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="fn-prep-sheet" />';
		printf( '<label>%s <input type="date" name="date" value="%s" /></label> ', esc_html__( 'Date', 'fastnutrition-mealprep' ), esc_attr( $date ) );
		printf( '<label>%s <select name="method">', esc_html__( 'Method', 'fastnutrition-mealprep' ) );
		foreach ( [
			''           => __( 'All', 'fastnutrition-mealprep' ),
			'delivery'   => __( 'Delivery only', 'fastnutrition-mealprep' ),
			'collection' => __( 'Collection only', 'fastnutrition-mealprep' ),
		] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $method, $val, false ), esc_html( $label ) );
		}
		echo '</select></label> ';
		submit_button( __( 'Update', 'fastnutrition-mealprep' ), 'primary', '', false );
		echo ' <button type="button" class="button" onclick="window.print()">' . esc_html__( 'Print', 'fastnutrition-mealprep' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Download PDF', 'fastnutrition-mealprep' ) . '</a>';
		echo '</form>';

		self::print_styles();
		$this->render_sheet( $date, $method, false );
		echo '</div>';
	}

	private function render_sheet( string $date, string $method, bool $for_pdf ): void {
		$matched = self::collect_matched_by_date( $date, $method );
		$totals  = PrepDashboard::get_day_totals( $date );
		/* translators: %s: prep date */
		$title   = sprintf( __( 'Prep Sheet — %s', 'fastnutrition-mealprep' ), $date );
		$this->render_sections( $matched, $totals, $title, $method, $for_pdf );
	}

	/**
	 * Stream a prep-sheet PDF for an explicit set of ticked orders (bulk action).
	 * Section-1 totals are summed from just those orders, not the date cache.
	 * Exits.
	 *
	 * @param int[] $order_ids
	 */
	public static function stream_for_orders( array $order_ids ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			wp_die( esc_html__( 'Dompdf is not installed. Run composer install.', 'fastnutrition-mealprep' ) );
		}

		$matched = self::collect_matched_by_ids( $order_ids );
		$totals  = self::totals_from_matched( $matched );
		$title   = sprintf(
			/* translators: %d: number of selected orders */
			_n( 'Prep Sheet — %d selected order', 'Prep Sheet — %d selected orders', count( $matched ), 'fastnutrition-mealprep' ),
			count( $matched )
		);

		ob_start();
		( new self() )->render_sections( $matched, $totals, $title, '', true );
		$html = (string) ob_get_clean();

		$dompdf = new \Dompdf\Dompdf( [ 'isRemoteEnabled' => false ] );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$dompdf->stream( 'prep-sheet-selected.pdf', [ 'Attachment' => true ] );
		exit;
	}

	/**
	 * Orders whose fulfilment date matches $date (and method, if given).
	 *
	 * @return array<int,array{order:\WC_Order,fulfilment:array}>
	 */
	public static function collect_matched_by_date( string $date, string $method, ?array $statuses = null ): array {
		$orders = wc_get_orders(
			[
				'status'       => $statuses ?? PrepOrderStatus::active_statuses(),
				'limit'        => -1,
				'meta_key'     => '_fn_fulfilment',
				'date_created' => '>=' . SlotAvailability::created_since_for_date( $date ),
			]
		);
		$matched = [];
		foreach ( $orders as $order ) {
			$ff = $order->get_meta( '_fn_fulfilment' );
			if ( ! is_array( $ff ) || ( $ff['date'] ?? '' ) !== $date ) {
				continue;
			}
			if ( '' !== $method && ( $ff['type'] ?? '' ) !== $method ) {
				continue;
			}
			$matched[] = [ 'order' => $order, 'fulfilment' => $ff ];
		}
		return $matched;
	}

	/**
	 * Build the matched list from explicit order IDs (bulk action). Orders with
	 * no fulfilment meta are still included (shown with a blank slot).
	 *
	 * @param int[] $order_ids
	 * @return array<int,array{order:\WC_Order,fulfilment:array}>
	 */
	private static function collect_matched_by_ids( array $order_ids ): array {
		$matched = [];
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order ) {
				continue;
			}
			$ff = $order->get_meta( '_fn_fulfilment' );
			$matched[] = [ 'order' => $order, 'fulfilment' => is_array( $ff ) ? $ff : [] ];
		}
		return $matched;
	}

	/**
	 * Ingredient portion totals computed directly from the matched orders'
	 * items — same selection parsing as OrderItemMeta::rebuild_prep_cache(), but
	 * not tied to the date cache. Same shape as PrepDashboard::get_day_totals().
	 *
	 * @param array<int,array{order:\WC_Order,fulfilment:array}> $matched
	 * @return array<int,array{ingredient_id:int,name:string,portions:int,type_slug:string}>
	 */
	private static function totals_from_matched( array $matched ): array {
		$counts = [];
		foreach ( $matched as $m ) {
			foreach ( $m['order']->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$sel = $item->get_meta( '_fn_selection', true );
				if ( ! is_array( $sel ) ) {
					continue;
				}
				$qty = (int) $item->get_quantity();
				foreach ( self::selection_ingredient_ids( $sel ) as $ing_id ) {
					$counts[ $ing_id ] = ( $counts[ $ing_id ] ?? 0 ) + $qty;
				}
			}
		}
		arsort( $counts );
		$out = [];
		foreach ( $counts as $ing_id => $portions ) {
			$out[] = [
				'ingredient_id' => (int) $ing_id,
				'name'          => (string) get_the_title( (int) $ing_id ),
				'portions'      => (int) $portions,
				'type_slug'     => Ingredient::get_type_slug( (int) $ing_id ),
			];
		}
		return $out;
	}

	/**
	 * Ingredient post IDs referenced by a single meal selection.
	 *
	 * @return int[]
	 */
	private static function selection_ingredient_ids( array $sel ): array {
		return Selection::ingredient_ids( $sel );
	}

	/**
	 * Add-on label => total quantity across a matched order set (each line's
	 * add-ons multiplied by its quantity). Add-ons are stored uniformly on the
	 * line selection meta by every order route, so this captures builder,
	 * standalone, sweet and quick-order orders alike.
	 *
	 * @param array<int,array{order:\WC_Order,fulfilment:array}> $matched
	 * @return array<string,int>
	 */
	public static function addon_totals( array $matched ): array {
		$totals = [];
		foreach ( $matched as $m ) {
			foreach ( $m['order']->get_items() as $item ) {
				$sel = $item->get_meta( '_fn_selection', true );
				if ( ! is_array( $sel ) ) {
					continue;
				}
				$qty = (int) $item->get_quantity();
				foreach ( Selection::addon_counts( $sel ) as $label => $n ) {
					$totals[ $label ] = ( $totals[ $label ] ?? 0 ) + ( $n * $qty );
				}
			}
		}
		arsort( $totals );
		return $totals;
	}

	/**
	 * Headline "items to make" counts for a matched order set: meals, sweets and
	 * add-ons (each weighted by line quantity), their grand total, and the meal
	 * split by fulfilment method. Lets kitchen staff reconcile the day's output.
	 *
	 * @param array<int,array{order:\WC_Order,fulfilment:array}> $matched
	 * @return array{meals:int,sweets:int,addons:int,total:int,delivery_meals:int,collection_meals:int}
	 */
	public static function fulfilment_totals( array $matched ): array {
		$t = [ 'meals' => 0, 'sweets' => 0, 'addons' => 0, 'total' => 0, 'delivery_meals' => 0, 'collection_meals' => 0 ];
		foreach ( $matched as $m ) {
			$method = (string) ( $m['fulfilment']['type'] ?? '' );
			foreach ( $m['order']->get_items() as $item ) {
				$qty = (int) $item->get_quantity();
				$sel = $item->get_meta( '_fn_selection', true );
				if ( is_array( $sel ) && Selection::is_sweet( $sel ) ) {
					$t['sweets'] += $qty;
				} else {
					$t['meals'] += $qty;
					if ( 'delivery' === $method ) {
						$t['delivery_meals'] += $qty;
					} elseif ( 'collection' === $method ) {
						$t['collection_meals'] += $qty;
					}
				}
				if ( is_array( $sel ) ) {
					foreach ( Selection::addon_counts( $sel ) as $n ) {
						$t['addons'] += $n * $qty;
					}
				}
			}
		}
		$t['total'] = $t['meals'] + $t['sweets'] + $t['addons'];
		return $t;
	}

	/**
	 * Render the three prep-sheet sections from a prepared order set + totals.
	 *
	 * @param array<int,array{order:\WC_Order,fulfilment:array}>                            $matched
	 * @param array<int,array{ingredient_id:int,name:string,portions:int,type_slug:string}> $totals
	 */
	private function render_sections( array $matched, array $totals, string $title, string $method, bool $for_pdf ): void {
		if ( $for_pdf ) {
			echo '<html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title>';
			self::print_styles();
			echo '</head><body>';
		}
		echo '<h1 class="fn-sheet-title">' . esc_html( $title ) . '</h1>';

		// Section 0 — Items-to-make summary (kitchen reconciliation count).
		$f = self::fulfilment_totals( $matched );
		echo '<section class="fn-sheet-section fn-sheet-summary">';
		printf(
			'<p class="fn-sheet-total"><strong>%s</strong> <span class="fn-sheet-bignum">%d</span></p>',
			esc_html__( 'Total items to make:', 'fastnutrition-mealprep' ),
			(int) $f['total']
		);
		printf(
			'<p class="fn-sheet-breakdown">%s %d &middot; %s %d &middot; %s %d</p>',
			esc_html__( 'Meals:', 'fastnutrition-mealprep' ),
			(int) $f['meals'],
			esc_html__( 'Sweets:', 'fastnutrition-mealprep' ),
			(int) $f['sweets'],
			esc_html__( 'Add-ons:', 'fastnutrition-mealprep' ),
			(int) $f['addons']
		);
		printf(
			'<p class="fn-sheet-breakdown">%s %d &middot; %s %d</p>',
			esc_html__( 'Delivery meals:', 'fastnutrition-mealprep' ),
			(int) $f['delivery_meals'],
			esc_html__( 'Collection meals:', 'fastnutrition-mealprep' ),
			(int) $f['collection_meals']
		);
		echo '</section>';

		// Section 1 — Ingredient totals.
		echo '<section class="fn-sheet-section"><h2>' . esc_html__( 'Ingredient totals', 'fastnutrition-mealprep' ) . '</h2>';
		$grouped = [];
		foreach ( $totals as $row ) {
			$grouped[ $row['type_slug'] ][] = $row;
		}
		$labels = [
			'protein'  => __( 'Proteins', 'fastnutrition-mealprep' ),
			'carb'     => __( 'Carbs', 'fastnutrition-mealprep' ),
			'greens'   => __( 'Greens', 'fastnutrition-mealprep' ),
			'set_meal' => __( 'Set Meals', 'fastnutrition-mealprep' ),
			'sweet'    => __( 'Sweets', 'fastnutrition-mealprep' ),
		];
		foreach ( $labels as $slug => $label ) {
			if ( empty( $grouped[ $slug ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $label ) . '</h3>';
			echo '<table class="fn-sheet-table"><thead><tr><th>' . esc_html__( 'Ingredient', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Portions', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
			foreach ( $grouped[ $slug ] as $row ) {
				echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . (int) $row['portions'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// Section 1b — Add-on totals (any route: builder, standalone, sweet, quick order).
		$addon_totals = self::addon_totals( $matched );
		if ( ! empty( $addon_totals ) ) {
			echo '<section class="fn-sheet-section"><h2>' . esc_html__( 'Add-ons', 'fastnutrition-mealprep' ) . '</h2>';
			echo '<table class="fn-sheet-table"><thead><tr><th>' . esc_html__( 'Add-on', 'fastnutrition-mealprep' ) . '</th><th>' . esc_html__( 'Quantity', 'fastnutrition-mealprep' ) . '</th></tr></thead><tbody>';
			foreach ( $addon_totals as $label => $count ) {
				echo '<tr><td>' . esc_html( (string) $label ) . '</td><td>' . (int) $count . '</td></tr>';
			}
			echo '</tbody></table></section>';
		}

		// Section 2 — Per-order pick list.
		echo '<section class="fn-sheet-section"><h2>' . esc_html__( 'Per-order pick list', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $matched ) ) {
			echo '<p><em>' . esc_html__( 'No orders for this filter.', 'fastnutrition-mealprep' ) . '</em></p>';
		} else {
			foreach ( $matched as $m ) {
				$order = $m['order'];
				$ff    = $m['fulfilment'];
				echo '<article class="fn-sheet-card">';
				printf(
					'<h3>#%d — %s <span class="fn-sheet-meta">(%s)</span></h3>',
					(int) $order->get_id(),
					esc_html( $order->get_formatted_billing_full_name() ),
					esc_html( (string) ( $ff['type'] ?? '' ) )
				);
				$slot = $ff['slot'] ?? [];
				echo '<p class="fn-sheet-meta">';
				echo esc_html( (string) ( $slot['start'] ?? '' ) . '–' . (string) ( $slot['end'] ?? '' ) );
				if ( 'delivery' === ( $ff['type'] ?? '' ) ) {
					echo ' · ' . esc_html( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() );
				}
				echo '</p>';
				echo '<ul class="fn-sheet-items">';
				foreach ( $order->get_items() as $item ) {
					$sel = $item->get_meta( '_fn_selection', true );
					$describe = is_array( $sel ) ? PrepDashboard::describe_selection( $sel ) : '';
					$addons = is_array( $sel ) && ! empty( $sel['addons'] ) ? array_filter( array_map( static fn( $a ) => (string) ( $a['label'] ?? '' ), $sel['addons'] ) ) : [];
					echo '<li>';
					echo '<label><input type="checkbox" disabled /> ';
					printf(
						'%d × <strong>%s</strong>%s%s',
						(int) $item->get_quantity(),
						esc_html( $item->get_name() ),
						'' !== $describe ? ' — ' . esc_html( $describe ) : '',
						! empty( $addons ) ? ' <em>(+ ' . esc_html( implode( ', ', $addons ) ) . ')</em>' : ''
					);
					echo '</label></li>';
				}
				echo '</ul></article>';
			}
		}
		echo '</section>';

		// Section 3 — Delivery run sheet.
		if ( '' === $method || 'delivery' === $method ) {
			echo '<section class="fn-sheet-section"><h2>' . esc_html__( 'Delivery run sheet', 'fastnutrition-mealprep' ) . '</h2>';
			$runs = [];
			foreach ( $matched as $m ) {
				$ff = $m['fulfilment'];
				if ( 'delivery' !== ( $ff['type'] ?? '' ) ) {
					continue;
				}
				$pid = (int) ( $ff['profile_id'] ?? 0 );
				$runs[ $pid ][] = $m;
			}
			if ( empty( $runs ) ) {
				echo '<p><em>' . esc_html__( 'No delivery orders.', 'fastnutrition-mealprep' ) . '</em></p>';
			} else {
				foreach ( $runs as $pid => $list ) {
					$profile = \FastNutrition\MealPrep\Delivery\Profile::get( $pid );
					echo '<h3>' . esc_html( $profile['name'] ?? ( __( 'Profile #', 'fastnutrition-mealprep' ) . $pid ) ) . '</h3>';
					echo '<table class="fn-sheet-table"><thead><tr>';
					echo '<th>' . esc_html__( 'Postcode', 'fastnutrition-mealprep' ) . '</th>';
					echo '<th>' . esc_html__( 'Customer', 'fastnutrition-mealprep' ) . '</th>';
					echo '<th>' . esc_html__( 'Slot', 'fastnutrition-mealprep' ) . '</th>';
					echo '<th>' . esc_html__( 'Meals', 'fastnutrition-mealprep' ) . '</th>';
					echo '</tr></thead><tbody>';
					foreach ( $list as $m ) {
						$order = $m['order'];
						$ff    = $m['fulfilment'];
						$slot  = $ff['slot'] ?? [];
						$total = 0;
						foreach ( $order->get_items() as $item ) {
							$total += (int) $item->get_quantity();
						}
						echo '<tr>';
						echo '<td>' . esc_html( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) . '</td>';
						echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
						echo '<td>' . esc_html( (string) ( $slot['start'] ?? '' ) . '–' . (string) ( $slot['end'] ?? '' ) ) . '</td>';
						echo '<td>' . (int) $total . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
				}
			}
			echo '</section>';
		}

		if ( $for_pdf ) {
			echo '</body></html>';
		}
	}

	private static function print_styles(): void {
		?>
		<style>
		@media print { .fn-no-print { display: none !important; } }
		.fn-sheet-section { margin: 20px 0; page-break-inside: avoid; }
		.fn-sheet-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
		.fn-sheet-table th, .fn-sheet-table td { border: 1px solid #333; padding: 6px 10px; text-align: left; }
		.fn-sheet-card { border: 1px solid #000; padding: 10px; margin-bottom: 10px; page-break-inside: avoid; }
		.fn-sheet-meta { color: #444; font-size: 12px; }
		.fn-sheet-items { list-style: none; padding-left: 0; }
		.fn-sheet-items li { padding: 4px 0; border-bottom: 1px dashed #ccc; }
		.fn-sheet-title { font-size: 20px; margin: 10px 0; }
		.fn-sheet-summary { border: 2px solid #000; padding: 8px 14px; }
		.fn-sheet-total { margin: 0 0 4px; font-size: 16px; }
		.fn-sheet-bignum { font-size: 26px; font-weight: 700; }
		.fn-sheet-breakdown { margin: 2px 0; color: #333; }
		</style>
		<?php
	}
}

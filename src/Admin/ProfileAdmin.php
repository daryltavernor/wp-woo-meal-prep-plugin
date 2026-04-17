<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\Profile;

final class ProfileAdmin {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_save' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['fn_profile_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fn_profile_nonce'] ) ), 'fn_save_profile' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = isset( $_POST['fn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_action'] ) ) : '';
		if ( 'delete' === $action && ! empty( $_POST['profile_id'] ) ) {
			Profile::delete( (int) $_POST['profile_id'] );
		} elseif ( 'save' === $action ) {
			$days = 0;
			foreach ( (array) ( $_POST['days'] ?? [] ) as $d ) {
				$days |= (int) $d;
			}
			$slots = [];
			foreach ( (array) ( $_POST['slots'] ?? [] ) as $row ) {
				$start = isset( $row['start'] ) ? sanitize_text_field( (string) $row['start'] ) : '';
				$end   = isset( $row['end'] ) ? sanitize_text_field( (string) $row['end'] ) : '';
				$cap   = isset( $row['capacity'] ) && '' !== $row['capacity'] ? max( 0, (int) $row['capacity'] ) : null;
				if ( $start && $end ) {
					$slots[] = array_filter( [ 'start' => $start, 'end' => $end, 'capacity' => $cap ], static fn( $v ) => null !== $v );
				}
			}
			$postcodes = [];
			$raw_pc    = isset( $_POST['postcodes'] ) ? (string) wp_unslash( $_POST['postcodes'] ) : '';
			foreach ( preg_split( '/[\r\n,]+/', $raw_pc ) as $pc ) {
				$pc = trim( (string) $pc );
				if ( '' !== $pc ) {
					$postcodes[] = strtoupper( preg_replace( '/\s+/', '', $pc ) ?? '' );
				}
			}

			$zone_ids = [];
			foreach ( (array) ( $_POST['zone_ids'] ?? [] ) as $zid ) {
				$zone_ids[] = (int) $zid;
			}

			Profile::save(
				[
					'id'        => isset( $_POST['profile_id'] ) ? (int) $_POST['profile_id'] : 0,
					'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
					'method'    => isset( $_POST['method'] ) ? sanitize_key( wp_unslash( (string) $_POST['method'] ) ) : 'delivery',
					'days'      => $days,
					'slots'     => $slots,
					'postcodes' => $postcodes,
					'zone_ids'  => $zone_ids,
					'priority'  => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10,
					'active'    => ! empty( $_POST['active'] ),
				]
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fn-delivery-profiles' ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}

		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing = $edit_id ? Profile::get( $edit_id ) : null;

		echo '<div class="wrap"><h1>' . esc_html__( 'Delivery & Collection Profiles', 'fastnutrition-mealprep' ) . '</h1>';
		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:14px 0;max-width:900px">';
		echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'What a profile is', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'A profile groups one or more postcodes with the days of the week you deliver there and the time windows for each day. For example: one profile covering ST10/ST9/ST5/ST7 on Tue/Thu/Sun, another covering ST3/ST6/ST8 on Wed/Fri. Collection profiles apply to everyone regardless of postcode.', 'fastnutrition-mealprep' );
		echo '</p>';
		echo '<p style="margin:6px 0 0"><strong>' . esc_html__( 'Conflicts', 'fastnutrition-mealprep' ) . '</strong> — ';
		echo esc_html__( 'If the same postcode is listed in more than one delivery profile, the Priority field decides which wins (lower = first). The dashboard widget also flags any WooCommerce shipping zone postcodes that no profile covers, so you can spot gaps before customers do.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		if ( $editing || isset( $_GET['new'] ) ) {
			$this->render_form( $editing ?? [] );
		}

		echo '<h2>' . esc_html__( 'Existing profiles', 'fastnutrition-mealprep' ) . '</h2>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=fn-delivery-profiles&new=1' ) ) . '">' . esc_html__( 'Add profile', 'fastnutrition-mealprep' ) . '</a></p>';

		$profiles = Profile::all( false );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Method', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Coverage', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Days', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Slots', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th>' . esc_html__( 'Active', 'fastnutrition-mealprep' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( empty( $profiles ) ) {
			echo '<tr><td colspan="7"><em>' . esc_html__( 'No profiles yet.', 'fastnutrition-mealprep' ) . '</em></td></tr>';
		}
		foreach ( $profiles as $p ) {
			echo '<tr>';
			echo '<td>' . esc_html( $p['name'] ) . '</td>';
			echo '<td>' . esc_html( $p['method'] ) . '</td>';
			$zone_parts = [];
			if ( class_exists( \WC_Shipping_Zones::class ) ) {
				foreach ( (array) ( $p['zone_ids'] ?? [] ) as $zid ) {
					$z = \WC_Shipping_Zones::get_zone( (int) $zid );
					if ( $z ) {
						$zone_parts[] = $z->get_zone_name();
					}
				}
			}
			$manual = count( (array) ( $p['postcodes'] ?? [] ) );
			$parts  = [];
			if ( ! empty( $zone_parts ) ) {
				$parts[] = sprintf( '<em>%s:</em> %s', esc_html__( 'Zones', 'fastnutrition-mealprep' ), esc_html( implode( ', ', $zone_parts ) ) );
			}
			if ( $manual ) {
				$parts[] = sprintf( '%d %s', $manual, esc_html__( 'manual postcodes', 'fastnutrition-mealprep' ) );
			}
			echo '<td>' . ( $parts ? wp_kses_post( implode( '<br>', $parts ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( self::days_label( (int) $p['days'] ) ) . '</td>';
			echo '<td>' . esc_html( count( $p['slots'] ) . ' ' . _n( 'slot', 'slots', count( $p['slots'] ), 'fastnutrition-mealprep' ) ) . '</td>';
			echo '<td>' . ( $p['active'] ? '✓' : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=fn-delivery-profiles&edit=' . (int) $p['id'] ) ) . '">' . esc_html__( 'Edit', 'fastnutrition-mealprep' ) . '</a>';
			echo ' · <form method="post" style="display:inline">';
			wp_nonce_field( 'fn_save_profile', 'fn_profile_nonce' );
			echo '<input type="hidden" name="fn_action" value="delete" /><input type="hidden" name="profile_id" value="' . (int) $p['id'] . '" />';
			echo '<button type="submit" class="button-link-delete" onclick="return confirm(\'Delete this profile?\')">' . esc_html__( 'Delete', 'fastnutrition-mealprep' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	private function render_form( array $p ): void {
		$days   = (int) ( $p['days'] ?? 0 );
		$slots  = (array) ( $p['slots'] ?? [] );
		if ( empty( $slots ) ) {
			$slots = [ [ 'start' => '09:00', 'end' => '12:00', 'capacity' => '' ] ];
		}

		echo '<h2>' . esc_html( empty( $p ) ? __( 'Add profile', 'fastnutrition-mealprep' ) : __( 'Edit profile', 'fastnutrition-mealprep' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'fn_save_profile', 'fn_profile_nonce' );
		echo '<input type="hidden" name="fn_action" value="save" />';
		if ( ! empty( $p['id'] ) ) {
			echo '<input type="hidden" name="profile_id" value="' . (int) $p['id'] . '" />';
		}

		echo '<table class="form-table"><tbody>';
		printf( '<tr><th><label for="fn_name">%s</label></th><td><input id="fn_name" type="text" name="name" value="%s" class="regular-text" required /><p class="description">%s</p></td></tr>',
			esc_html__( 'Name', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $p['name'] ?? '' ) ),
			esc_html__( 'Internal label, e.g. "North Staffs Tue/Thu/Sun". Only shown in admin.', 'fastnutrition-mealprep' )
		);
		printf(
			'<tr><th><label for="fn_method">%s</label></th><td><select id="fn_method" name="method"><option value="delivery" %s>%s</option><option value="collection" %s>%s</option></select><p class="description">%s</p></td></tr>',
			esc_html__( 'Method', 'fastnutrition-mealprep' ),
			selected( (string) ( $p['method'] ?? 'delivery' ), 'delivery', false ),
			esc_html__( 'Delivery', 'fastnutrition-mealprep' ),
			selected( (string) ( $p['method'] ?? '' ), 'collection', false ),
			esc_html__( 'Collection', 'fastnutrition-mealprep' ),
			esc_html__( 'Delivery profiles are matched against the customer\'s postcode. Collection profiles are offered to everyone regardless of postcode.', 'fastnutrition-mealprep' )
		);

		echo '<tr><th>' . esc_html__( 'Days', 'fastnutrition-mealprep' ) . '</th><td>';
		$day_map = [
			Profile::DAY_MON => __( 'Mon', 'fastnutrition-mealprep' ),
			Profile::DAY_TUE => __( 'Tue', 'fastnutrition-mealprep' ),
			Profile::DAY_WED => __( 'Wed', 'fastnutrition-mealprep' ),
			Profile::DAY_THU => __( 'Thu', 'fastnutrition-mealprep' ),
			Profile::DAY_FRI => __( 'Fri', 'fastnutrition-mealprep' ),
			Profile::DAY_SAT => __( 'Sat', 'fastnutrition-mealprep' ),
			Profile::DAY_SUN => __( 'Sun', 'fastnutrition-mealprep' ),
		];
		foreach ( $day_map as $mask => $label ) {
			printf(
				'<label style="margin-right:1em"><input type="checkbox" name="days[]" value="%d" %s /> %s</label>',
				(int) $mask,
				checked( (bool) ( $days & $mask ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Tick every day of the week this profile operates. The slot picker at checkout will automatically offer matching dates within the next 14 days.', 'fastnutrition-mealprep' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Time slots', 'fastnutrition-mealprep' ) . '</th><td><p class="description" style="margin-top:0">' . esc_html__( 'One row per time window you offer (e.g. 09:00–12:00, 17:00–20:00). Capacity limits the number of bookings per window — leave blank for unlimited.', 'fastnutrition-mealprep' ) . '</p><table id="fn-slots"><tbody>';
		foreach ( $slots as $i => $s ) {
			echo '<tr>';
			printf( '<td><input type="time" name="slots[%1$d][start]" value="%2$s" required /> – <input type="time" name="slots[%1$d][end]" value="%3$s" required /></td>',
				(int) $i,
				esc_attr( (string) ( $s['start'] ?? '' ) ),
				esc_attr( (string) ( $s['end'] ?? '' ) )
			);
			printf( '<td><input type="number" min="0" placeholder="%s" name="slots[%1$d][capacity]" value="%2$s" /></td>',
				esc_attr__( 'Capacity (blank = unlimited)', 'fastnutrition-mealprep' ),
				esc_attr( (string) ( $s['capacity'] ?? '' ) )
			);
			echo '<td><button type="button" class="button fn-slot-remove">&times;</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="fn-slot-add">' . esc_html__( 'Add slot', 'fastnutrition-mealprep' ) . '</button></p></td></tr>';

		// WooCommerce shipping zones multi-select.
		$selected_zones = array_map( 'intval', (array) ( $p['zone_ids'] ?? [] ) );
		echo '<tr><th>' . esc_html__( 'WooCommerce shipping zones', 'fastnutrition-mealprep' ) . '</th><td>';
		if ( class_exists( \WC_Shipping_Zones::class ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			if ( empty( $zones ) ) {
				echo '<p class="description">' . esc_html__( 'No shipping zones configured yet. Add zones under WooCommerce → Settings → Shipping.', 'fastnutrition-mealprep' ) . '</p>';
			} else {
				echo '<p class="description" style="margin-top:0">' . esc_html__( 'Tick one or more zones to automatically inherit their postcodes. Useful when you already maintain postcodes in shipping zones — you do not need to duplicate them below.', 'fastnutrition-mealprep' ) . '</p>';
				echo '<div style="max-height:260px;overflow:auto;border:1px solid #ccc;padding:8px;max-width:600px">';
				foreach ( $zones as $zone ) {
					$zone_id   = (int) ( $zone['id'] ?? $zone['zone_id'] ?? 0 );
					$zone_name = (string) ( $zone['zone_name'] ?? '' );
					$postcodes_in_zone = [];
					foreach ( (array) ( $zone['zone_locations'] ?? [] ) as $location ) {
						if ( is_object( $location ) && 'postcode' === ( $location->type ?? '' ) ) {
							$postcodes_in_zone[] = (string) $location->code;
						}
					}
					$methods = [];
					foreach ( (array) ( $zone['shipping_methods'] ?? [] ) as $m ) {
						if ( is_object( $m ) && method_exists( $m, 'get_title' ) ) {
							$methods[] = $m->get_title();
						}
					}
					printf(
						'<label style="display:block;padding:6px;border-bottom:1px solid #eee">
							<input type="checkbox" name="zone_ids[]" value="%1$d" %2$s />
							<strong>%3$s</strong>
							%4$s
							%5$s
						</label>',
						$zone_id,
						checked( in_array( $zone_id, $selected_zones, true ), true, false ),
						esc_html( $zone_name ),
						! empty( $postcodes_in_zone ) ? '<br><small style="color:#555">' . esc_html__( 'Postcodes:', 'fastnutrition-mealprep' ) . ' <code>' . esc_html( implode( ', ', array_slice( $postcodes_in_zone, 0, 12 ) ) ) . ( count( $postcodes_in_zone ) > 12 ? ' …' : '' ) . '</code></small>' : '<br><small style="color:#888">' . esc_html__( 'No postcode restrictions on this zone.', 'fastnutrition-mealprep' ) . '</small>',
						! empty( $methods ) ? '<br><small style="color:#555">' . esc_html__( 'Methods:', 'fastnutrition-mealprep' ) . ' ' . esc_html( implode( ', ', $methods ) ) . '</small>' : ''
					);
				}
				echo '</div>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'WooCommerce not available — can\'t load zones.', 'fastnutrition-mealprep' ) . '</p>';
		}
		echo '</td></tr>';

		printf( '<tr><th><label for="fn_postcodes">%s</label></th><td><textarea id="fn_postcodes" name="postcodes" rows="4" cols="40" placeholder="%s">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Additional postcodes (optional)', 'fastnutrition-mealprep' ),
			esc_attr__( 'One per line. Use * for wildcard (e.g. ST10*) or a prefix (e.g. ST10).', 'fastnutrition-mealprep' ),
			esc_textarea( implode( "\n", (array) ( $p['postcodes'] ?? [] ) ) ),
			esc_html__( 'Manual list, combined with any postcodes inherited from the zones above. Leave empty if zones cover everything. Collection profiles ignore postcode matching entirely.', 'fastnutrition-mealprep' )
		);

		printf( '<tr><th><label for="fn_priority">%s</label></th><td><input id="fn_priority" type="number" name="priority" value="%d" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Priority', 'fastnutrition-mealprep' ),
			(int) ( $p['priority'] ?? 10 ),
			esc_html__( 'Lower numbers are matched first when postcodes overlap.', 'fastnutrition-mealprep' )
		);

		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="active" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Active', 'fastnutrition-mealprep' ),
			checked( (bool) ( $p['active'] ?? true ), true, false ),
			esc_html__( 'Available to customers', 'fastnutrition-mealprep' )
		);

		echo '</tbody></table>';
		submit_button( empty( $p ) ? __( 'Create profile', 'fastnutrition-mealprep' ) : __( 'Save profile', 'fastnutrition-mealprep' ) );
		echo '</form>';
		?>
		<script>
		(function(){
			const add = document.getElementById('fn-slot-add');
			const tbody = document.querySelector('#fn-slots tbody');
			if (!add || !tbody) return;
			add.addEventListener('click', function () {
				const i = tbody.children.length;
				const tr = document.createElement('tr');
				tr.innerHTML = '<td><input type="time" name="slots[' + i + '][start]" required /> – <input type="time" name="slots[' + i + '][end]" required /></td>' +
					'<td><input type="number" min="0" placeholder="Capacity" name="slots[' + i + '][capacity]" /></td>' +
					'<td><button type="button" class="button fn-slot-remove">&times;</button></td>';
				tbody.appendChild(tr);
			});
			tbody.addEventListener('click', function (e) {
				if (e.target && e.target.classList.contains('fn-slot-remove')) {
					e.target.closest('tr').remove();
				}
			});
		})();
		</script>
		<?php
	}

	public static function days_label( int $mask ): string {
		$names = [];
		foreach ( [ Profile::DAY_MON => 'Mon', Profile::DAY_TUE => 'Tue', Profile::DAY_WED => 'Wed', Profile::DAY_THU => 'Thu', Profile::DAY_FRI => 'Fri', Profile::DAY_SAT => 'Sat', Profile::DAY_SUN => 'Sun' ] as $m => $n ) {
			if ( $mask & $m ) {
				$names[] = $n;
			}
		}
		return $names ? implode( ', ', $names ) : '—';
	}
}

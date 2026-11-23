<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\FoodSafety;

/**
 * Prep Records: the tablet-friendly screen where staff type the day's readings
 * instead of writing them. Same rows as the Cook & Pack Record; "Now" buttons
 * stamp the current time; readings outside the limits are flagged and ask for
 * a note of what was done. Saved rows feed the archive and the printed sheet.
 */
final class PrepRecordsPage {

	public const PAGE_SLUG = 'fn-prep-records';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_post' ] );
	}

	public static function render_static(): void {
		( new self() )->render();
	}

	// --- Save ----------------------------------------------------------------------

	public function handle_post(): void {
		if ( ! isset( $_POST['fn_prep_records_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'fn_prep_records_save', 'fn_prep_records_nonce' );

		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
		$action  = isset( $_POST['fn_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['fn_action'] ) ) : 'save';
		$user_id = get_current_user_id();
		$notice  = 'saved';
		$flagged = 0;

		if ( 'add_batch' === $action ) {
			$iid = isset( $_POST['add_ingredient_id'] ) ? (int) $_POST['add_ingredient_id'] : 0;
			$this->add_batch( $date, $iid, $user_id );
			$notice = 'batch_added';
		} elseif ( 'delete_batch' === $action ) {
			$code = isset( $_POST['delete_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['delete_code'] ) ) : '';
			if ( '' !== $code && BatchCodes::sequence( $code ) > 1 ) {
				PrepRecordsRepository::delete_row( $date, $code );
			}
			$notice = 'batch_deleted';
		} else {
			$flagged = $this->save_day( $date, $user_id );
		}

		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&date=' . rawurlencode( $date ) . '&notice=' . $notice . ( $flagged ? '&flagged=' . $flagged : '' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/** Returns the number of rows flagged out of limit. */
	private function save_day( string $date, int $user_id ): int {
		$settings = FoodSafetySettings::get();
		$posted   = isset( $_POST['rec'] ) && is_array( $_POST['rec'] ) ? wp_unslash( $_POST['rec'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- verified by caller; sanitised in the repository.
		$base     = [];
		foreach ( CookPackRecord::merged_rows( $date, false ) as $row ) {
			$base[ (string) $row['batch_code'] ] = $row;
		}
		$saved   = PrepRecordsRepository::get_day( $date );
		$real    = [];
		$samples = [];
		$flagged = 0;

		foreach ( $posted as $code => $fields ) {
			$code = (string) $code;
			if ( ! isset( $base[ $code ] ) || ! is_array( $fields ) ) {
				continue;
			}
			$row     = $base[ $code ];
			$entered = [];
			foreach ( PrepRecordsRepository::FIELDS as $f ) {
				$entered[ $f ] = isset( $fields[ $f ] ) ? trim( (string) $fields[ $f ] ) : '';
			}
			$blank = '' === implode( '', $entered );
			if ( $blank && ! isset( $saved[ $code ] ) ) {
				continue; // Nothing typed and nothing stored: leave it unrecorded.
			}
			$eval  = PrepRecordsRepository::evaluate( $entered, $row, $settings );
			$merge = array_merge( $row, $entered, [ 'out_of_limit' => $eval['out_of_limit'] ? 1 : 0 ] );
			if ( $eval['out_of_limit'] ) {
				$flagged++;
			}
			// A sample row that was saved unchanged stays a sample; any edit makes it real.
			if ( isset( $saved[ $code ] ) && $saved[ $code ]['is_sample'] && PrepRecordsRepository::same_entries( $entered, $saved[ $code ] ) ) {
				$samples[ $code ] = $merge;
			} else {
				$real[ $code ] = $merge;
			}
		}
		if ( $real ) {
			PrepRecordsRepository::upsert_rows( $date, $real, $user_id, 0 );
		}
		if ( $samples ) {
			PrepRecordsRepository::upsert_rows( $date, $samples, $user_id, 1 );
		}

		// Daily checks.
		$checks_in = isset( $_POST['chk'] ) && is_array( $_POST['chk'] ) ? wp_unslash( $_POST['chk'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		$kv        = [];
		foreach ( self::check_keys( $settings ) as $key ) {
			$kv[ $key ] = isset( $checks_in[ $key ] ) ? trim( (string) $checks_in[ $key ] ) : '';
		}
		foreach ( [ 'opening', 'closing', 'cleaning' ] as $k ) {
			$kv[ $k ] = empty( $checks_in[ $k ] ) ? '' : '1';
		}
		$stored    = PrepRecordsRepository::get_checks( $date );
		$unchanged = ! empty( $stored['__sample'] );
		if ( $unchanged ) {
			foreach ( $kv as $k => $v ) {
				if ( (string) ( $stored[ $k ] ?? '' ) !== (string) $v ) {
					$unchanged = false;
					break;
				}
			}
		}
		if ( '' !== implode( '', $kv ) || ! empty( $stored ) ) {
			PrepRecordsRepository::save_checks( $date, $kv, $unchanged ? 1 : 0 );
		}
		return $flagged;
	}

	/** Keys of the free-value daily checks (temperatures, times, calibration, sign-off, notes). */
	private static function check_keys( array $settings ): array {
		$keys = [ 'am_time', 'pm_time', 'probe_iced', 'probe_boiling', 'signoff', 'notes' ];
		foreach ( array_merge( (array) $settings['fridges'], (array) $settings['freezers'] ) as $unit ) {
			$keys[] = 'unit_am:' . $unit;
			$keys[] = 'unit_pm:' . $unit;
		}
		return $keys;
	}

	private function add_batch( string $date, int $ingredient_id, int $user_id ): void {
		foreach ( CookPackRecord::rows( $date ) as $row ) {
			if ( (int) $row['ingredient_id'] !== $ingredient_id ) {
				continue;
			}
			$seq = 1;
			foreach ( PrepRecordsRepository::get_day( $date ) as $saved ) {
				if ( (int) $saved['ingredient_id'] === $ingredient_id ) {
					$seq = max( $seq, BatchCodes::sequence( $saved['batch_code'] ) );
				}
			}
			$code = BatchCodes::with_sequence( (string) $row['batch_code'], $seq + 1 );
			$row['batch_code'] = $code;
			PrepRecordsRepository::upsert_rows( $date, [ $code => $row ], $user_id, 0 );
			return;
		}
	}

	// --- Render --------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fastnutrition-mealprep' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query args.
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = wp_date( 'Y-m-d' );
		}
		$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		$flagged = isset( $_GET['flagged'] ) ? (int) $_GET['flagged'] : 0;
		// phpcs:enable

		$settings = FoodSafetySettings::get();
		$rows     = CookPackRecord::merged_rows( $date, false );
		$checks   = PrepRecordsRepository::get_checks( $date );
		$ts       = (int) strtotime( $date . ' 12:00:00' );
		$prev     = gmdate( 'Y-m-d', $ts - DAY_IN_SECONDS );
		$next     = gmdate( 'Y-m-d', $ts + DAY_IN_SECONDS );
		$self     = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$calib    = FoodSafetySettings::is_calibration_day( $date );

		echo '<div class="wrap fn-prep-records"><h1>' . esc_html__( 'Prep Records', 'fastnutrition-mealprep' ) . ' <span style="font-weight:normal;color:#555">' . esc_html( wp_date( 'l j F Y', $ts ) ) . '</span></h1>';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Records saved.', 'fastnutrition-mealprep' ) . '</p></div>';
		} elseif ( 'batch_added' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Extra batch added.', 'fastnutrition-mealprep' ) . '</p></div>';
		} elseif ( 'batch_deleted' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Batch removed.', 'fastnutrition-mealprep' ) . '</p></div>';
		}
		if ( $flagged > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %d: number of flagged batches */ _n( '%d batch is outside the limits. Add what was done in its "Action taken" box.', '%d batches are outside the limits. Add what was done in their "Action taken" boxes.', $flagged, 'fastnutrition-mealprep' ), $flagged ) ) . '</p></div>';
		}

		echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:12px 0;max-width:1100px">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'What this page is for', 'fastnutrition-mealprep' ) . '</strong><br>';
		echo esc_html__( 'Type the probe readings and times for each batch as you go (tap "Now" to stamp the current time), tick the daily checks, and Save. Readings below target or outside the cooling window are flagged and need a note of what was done. The Cook & Pack Record prints whatever is saved here.', 'fastnutrition-mealprep' );
		echo '</p></div>';

		// Date navigation.
		echo '<div class="fn-pr-nav">';
		echo '<a class="button" href="' . esc_url( $self . '&date=' . $prev ) . '">&larr; ' . esc_html__( 'Previous day', 'fastnutrition-mealprep' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $self . '&date=' . wp_date( 'Y-m-d' ) ) . '">' . esc_html__( 'Today', 'fastnutrition-mealprep' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $self . '&date=' . $next ) . '">' . esc_html__( 'Next day', 'fastnutrition-mealprep' ) . ' &rarr;</a> ';
		echo '<form method="get" style="display:inline-flex;gap:6px;align-items:center;margin-left:10px"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" /><input type="date" name="date" value="' . esc_attr( $date ) . '" /><button class="button">' . esc_html__( 'Go', 'fastnutrition-mealprep' ) . '</button></form>';
		echo ' <a class="button" style="margin-left:10px" href="' . esc_url( admin_url( 'admin.php?page=' . CookPackRecord::PAGE_SLUG . '&date=' . $date ) ) . '">' . esc_html__( 'Print Cook & Pack Record', 'fastnutrition-mealprep' ) . '</a>';
		echo '</div>';

		self::styles();

		echo '<form method="post" id="fn-pr-form">';
		wp_nonce_field( 'fn_prep_records_save', 'fn_prep_records_nonce' );
		echo '<input type="hidden" name="date" value="' . esc_attr( $date ) . '" />';
		echo '<input type="hidden" name="fn_action" id="fn_action" value="save" />';
		echo '<input type="hidden" name="delete_code" id="fn_delete_code" value="" />';

		// Daily checks.
		echo '<h2>' . esc_html__( 'Daily checks', 'fastnutrition-mealprep' ) . ( ! empty( $checks['__sample'] ) ? ' <span class="fn-pr-badge">' . esc_html__( 'Sample', 'fastnutrition-mealprep' ) . '</span>' : '' ) . '</h2>';
		echo '<div class="fn-pr-checks">';
		echo '<table class="fn-pr-units"><thead><tr><th></th><th>' . esc_html__( 'AM', 'fastnutrition-mealprep' ) . ' ' . $this->time_input( 'chk[am_time]', (string) ( $checks['am_time'] ?? '' ), 'chk_am_time' ) . '</th><th>' . esc_html__( 'PM', 'fastnutrition-mealprep' ) . ' ' . $this->time_input( 'chk[pm_time]', (string) ( $checks['pm_time'] ?? '' ), 'chk_pm_time' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		$i = 0;
		foreach ( array_merge( (array) $settings['fridges'], (array) $settings['freezers'] ) as $unit ) {
			$i++;
			printf(
				'<tr><td>%s</td><td><input type="number" step="0.1" inputmode="decimal" name="chk[unit_am:%2$s]" value="%3$s" class="fn-pr-num" /> °C</td><td><input type="number" step="0.1" inputmode="decimal" name="chk[unit_pm:%2$s]" value="%4$s" class="fn-pr-num" /> °C</td></tr>',
				esc_html( (string) $unit ),
				esc_attr( (string) $unit ),
				esc_attr( (string) ( $checks[ 'unit_am:' . $unit ] ?? '' ) ),
				esc_attr( (string) ( $checks[ 'unit_pm:' . $unit ] ?? '' ) )
			);
		}
		echo '</tbody></table>';
		echo '<div class="fn-pr-other">';
		printf(
			'<p><strong>%s</strong>%s<br>%s <input type="number" step="0.1" inputmode="decimal" name="chk[probe_iced]" value="%s" class="fn-pr-num" /> °C &nbsp; %s <input type="number" step="0.1" inputmode="decimal" name="chk[probe_boiling]" value="%s" class="fn-pr-num" /> °C</p>',
			esc_html__( 'Probe calibration', 'fastnutrition-mealprep' ),
			$calib ? ' <em>' . esc_html__( '(due today)', 'fastnutrition-mealprep' ) . '</em>' : '',
			esc_html__( 'Iced water', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $checks['probe_iced'] ?? '' ) ),
			esc_html__( 'Boiling', 'fastnutrition-mealprep' ),
			esc_attr( (string) ( $checks['probe_boiling'] ?? '' ) )
		);
		printf(
			'<p><label><input type="checkbox" name="chk[opening]" value="1" %s /> %s</label> &nbsp; <label><input type="checkbox" name="chk[closing]" value="1" %s /> %s</label> &nbsp; <label><input type="checkbox" name="chk[cleaning]" value="1" %s /> %s</label></p>',
			checked( ! empty( $checks['opening'] ), true, false ),
			esc_html__( 'Opening checks done', 'fastnutrition-mealprep' ),
			checked( ! empty( $checks['closing'] ), true, false ),
			esc_html__( 'Closing checks done', 'fastnutrition-mealprep' ),
			checked( ! empty( $checks['cleaning'] ), true, false ),
			esc_html__( 'Cleaning schedule done', 'fastnutrition-mealprep' )
		);
		printf(
			'<p>%s (%s) <input type="text" name="chk[signoff]" value="%s" class="fn-pr-init" maxlength="10" placeholder="%s" /></p>',
			esc_html__( 'Signed off by', 'fastnutrition-mealprep' ),
			esc_html( (string) $settings['signatory'] ),
			esc_attr( (string) ( $checks['signoff'] ?? '' ) ),
			esc_attr__( 'Initials', 'fastnutrition-mealprep' )
		);
		printf(
			'<p><label>%s<br><textarea name="chk[notes]" rows="2" class="large-text">%s</textarea></label></p>',
			esc_html__( 'Notes for the day', 'fastnutrition-mealprep' ),
			esc_textarea( (string) ( $checks['notes'] ?? '' ) )
		);
		echo '</div></div>';

		// Batches.
		echo '<h2>' . esc_html__( 'Batches', 'fastnutrition-mealprep' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No components on the prep list for this date.', 'fastnutrition-mealprep' ) . '</em></p>';
		} else {
			echo '<div class="fn-pr-scroll"><table class="widefat fn-pr-table"><thead><tr>';
			foreach ( [ __( 'Batch', 'fastnutrition-mealprep' ), __( 'Component', 'fastnutrition-mealprep' ), __( 'Portions', 'fastnutrition-mealprep' ), __( 'Target', 'fastnutrition-mealprep' ), __( 'Time in', 'fastnutrition-mealprep' ), __( 'Time out', 'fastnutrition-mealprep' ), __( 'Core 1 °C', 'fastnutrition-mealprep' ), __( 'Core 2 °C', 'fastnutrition-mealprep' ), __( 'Cooled by', 'fastnutrition-mealprep' ), __( 'Fridge', 'fastnutrition-mealprep' ), __( 'Packed', 'fastnutrition-mealprep' ), __( 'Use-by', 'fastnutrition-mealprep' ), __( 'Initials', 'fastnutrition-mealprep' ), '' ] as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			$units = array_merge( (array) $settings['fridges'], (array) $settings['freezers'] );
			foreach ( $rows as $r ) {
				$code  = (string) $r['batch_code'];
				$id    = 'r' . md5( $code );
				$flag  = ! empty( $r['out_of_limit'] );
				$n     = 'rec[' . $code . ']';
				$badge = ! empty( $r['is_sample'] ) ? ' <span class="fn-pr-badge">' . esc_html__( 'Sample', 'fastnutrition-mealprep' ) . '</span>' : '';
				echo '<tr' . ( $flag ? ' class="fn-pr-flag"' : '' ) . '>';
				echo '<td class="fn-pr-code">' . esc_html( BatchCodes::short( $code ) ) . $badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td><strong>' . esc_html( (string) $r['name'] ) . '</strong><br><small>' . esc_html( (string) $r['method_label'] ) . ( '' !== (string) $r['typical'] ? ' · ' . esc_html( (string) $r['typical'] ) : '' ) . '</small></td>';
				echo '<td class="num">' . (int) $r['portions'] . '</td>';
				if ( ! empty( $r['no_cook'] ) ) {
					echo '<td class="num">—</td>';
					echo '<td>' . $this->time_input( $n . '[time_in]', (string) ( $r['time_in'] ?? '' ), $id . '_in', __( 'Checked', 'fastnutrition-mealprep' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<td colspan="5"><em>' . esc_html__( 'No cook — ready to eat. Supplier use-by checked.', 'fastnutrition-mealprep' ) . '</em></td>';
				} else {
					echo '<td class="num">' . esc_html( CookPackRecord::fmt_num( $r['target_c'] ) ) . ' °C</td>';
					echo '<td>' . $this->time_input( $n . '[time_in]', (string) ( $r['time_in'] ?? '' ), $id . '_in' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<td>' . $this->time_input( $n . '[time_out]', (string) ( $r['time_out'] ?? '' ), $id . '_out' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					printf( '<td><input type="number" step="0.1" inputmode="decimal" name="%s[probe1_c]" value="%s" class="fn-pr-num" /></td>', esc_attr( $n ), esc_attr( (string) ( $r['probe1_c'] ?? '' ) ) );
					printf( '<td><input type="number" step="0.1" inputmode="decimal" name="%s[probe2_c]" value="%s" class="fn-pr-num" /></td>', esc_attr( $n ), esc_attr( (string) ( $r['probe2_c'] ?? '' ) ) );
					if ( ! empty( $r['cooling'] ) ) {
						echo '<td>' . $this->time_input( $n . '[cooled_by]', (string) ( $r['cooled_by'] ?? '' ), $id . '_cool' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo '<td><select name="' . esc_attr( $n ) . '[fridge_unit]" class="fn-pr-unit"><option value="">—</option>';
						foreach ( $units as $u ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $u ), selected( (string) ( $r['fridge_unit'] ?? '' ), (string) $u, false ), esc_html( (string) $u ) );
						}
						echo '</select></td>';
					} else {
						echo '<td>—</td><td>—</td>';
					}
				}
				echo '<td>' . $this->time_input( $n . '[packed_at]', (string) ( $r['packed_at'] ?? '' ), $id . '_pack' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( '<td><input type="date" name="%s[use_by]" value="%s" class="fn-pr-date" /></td>', esc_attr( $n ), esc_attr( (string) ( $r['use_by'] ?? '' ) ) );
				printf( '<td><input type="text" name="%s[initials]" value="%s" class="fn-pr-init fn-pr-initials" maxlength="10" list="fn-pr-initials-list" /></td>', esc_attr( $n ), esc_attr( (string) ( $r['initials'] ?? '' ) ) );
				echo '<td>';
				if ( (int) $r['seq'] > 1 ) {
					echo '<button type="button" class="button-link-delete fn-pr-delete" data-code="' . esc_attr( $code ) . '">' . esc_html__( 'Remove', 'fastnutrition-mealprep' ) . '</button>';
				}
				echo '</td></tr>';
				// Corrective action row (always present, highlighted when flagged).
				printf(
					'<tr class="fn-pr-action%s"><td></td><td colspan="13"><label>%s <input type="text" name="%s[corrective_action]" value="%s" class="regular-text" placeholder="%s" /></label></td></tr>',
					$flag ? ' fn-pr-flag' : '',
					esc_html__( 'Action taken (if outside limits):', 'fastnutrition-mealprep' ),
					esc_attr( $n ),
					esc_attr( (string) ( $r['corrective_action'] ?? '' ) ),
					esc_attr__( 'e.g. returned to oven 8 min, re-probed 79 °C', 'fastnutrition-mealprep' )
				);
			}
			echo '</tbody></table></div>';

			// Initials suggestions + add batch.
			echo '<datalist id="fn-pr-initials-list">';
			foreach ( (array) $settings['sample']['initials'] as $ini ) {
				echo '<option value="' . esc_attr( (string) $ini ) . '"></option>';
			}
			echo '</datalist>';

			echo '<p class="fn-pr-actions">';
			echo '<button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save records', 'fastnutrition-mealprep' ) . '</button> ';
			echo '<button type="button" class="button" id="fn-pr-fill-initials">' . esc_html__( 'Fill blank initials with mine', 'fastnutrition-mealprep' ) . '</button>';
			echo '</p>';

			echo '<p class="fn-pr-addbatch"><label>' . esc_html__( 'Second batch of', 'fastnutrition-mealprep' ) . ' <select name="add_ingredient_id">';
			foreach ( CookPackRecord::rows( $date ) as $row ) {
				printf( '<option value="%d">%s</option>', (int) $row['ingredient_id'], esc_html( (string) $row['name'] ) );
			}
			echo '</select></label> <button type="button" class="button" id="fn-pr-add-batch">' . esc_html__( 'Add batch row', 'fastnutrition-mealprep' ) . '</button> <span class="description">' . esc_html__( 'Use when a component is cooked in more than one batch; the new row gets the next batch number.', 'fastnutrition-mealprep' ) . '</span></p>';
		}
		echo '</form></div>';
		self::script();
	}

	private function time_input( string $name, string $value, string $id, string $label = '' ): string {
		$html = sprintf(
			'<span class="fn-pr-timewrap">%s<input type="time" id="%s" name="%s" value="%s" class="fn-pr-time" /><button type="button" class="button fn-pr-now" data-target="%s" title="%s">%s</button></span>',
			'' !== $label ? '<small>' . esc_html( $label ) . '</small> ' : '',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $id ),
			esc_attr__( 'Stamp the current time', 'fastnutrition-mealprep' ),
			esc_html__( 'Now', 'fastnutrition-mealprep' )
		);
		return $html;
	}

	private static function styles(): void {
		?>
		<style>
		.fn-prep-records input[type=number], .fn-prep-records input[type=time], .fn-prep-records input[type=date], .fn-prep-records input[type=text], .fn-prep-records select { min-height: 38px; font-size: 15px; }
		.fn-pr-num { width: 80px !important; }
		.fn-pr-init { width: 70px !important; text-transform: uppercase; }
		.fn-pr-time { width: 105px !important; }
		.fn-pr-date { width: 150px !important; }
		.fn-pr-unit { max-width: 150px; }
		.fn-pr-timewrap { white-space: nowrap; }
		.fn-pr-now { margin-left: 2px !important; min-height: 38px; }
		.fn-pr-nav { margin: 10px 0 16px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
		.fn-pr-checks { display: flex; flex-wrap: wrap; gap: 24px; background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin-bottom: 20px; max-width: 1100px; }
		.fn-pr-units th, .fn-pr-units td { padding: 4px 10px 4px 0; text-align: left; }
		.fn-pr-other p { margin: 6px 0; }
		.fn-pr-scroll { overflow-x: auto; }
		.fn-pr-table td, .fn-pr-table th { vertical-align: middle; white-space: nowrap; }
		.fn-pr-table td.num { text-align: right; }
		.fn-pr-code { font-family: monospace; font-weight: 600; }
		.fn-pr-flag td { background: #fcf0f1 !important; }
		.fn-pr-action td { border-top: 0; padding-top: 0; }
		.fn-pr-badge { display: inline-block; background: #dba617; color: #1d2327; font-size: 10px; padding: 1px 6px; border-radius: 3px; vertical-align: middle; font-family: sans-serif; font-weight: 600; }
		.fn-pr-actions { margin: 16px 0; display: flex; gap: 10px; align-items: center; }
		</style>
		<?php
	}

	private static function script(): void {
		?>
		<script>
		(function () {
			var pad = function (n) { return (n < 10 ? '0' : '') + n; };
			document.querySelectorAll('.fn-pr-now').forEach(function (b) {
				b.addEventListener('click', function () {
					var i = document.getElementById(b.getAttribute('data-target'));
					if (!i) { return; }
					var d = new Date();
					i.value = pad(d.getHours()) + ':' + pad(d.getMinutes());
					i.dispatchEvent(new Event('change', { bubbles: true }));
				});
			});
			var KEY = 'fnPrepRecordsInitials';
			document.querySelectorAll('.fn-pr-initials').forEach(function (i) {
				i.addEventListener('change', function () {
					var v = i.value.trim().toUpperCase();
					i.value = v;
					if (v) { try { window.localStorage.setItem(KEY, v); } catch (e) {} }
				});
			});
			var fill = document.getElementById('fn-pr-fill-initials');
			if (fill) {
				var mine = '';
				try { mine = window.localStorage.getItem(KEY) || ''; } catch (e) {}
				if (mine) { fill.textContent = fill.textContent + ' (' + mine + ')'; } else { fill.disabled = true; }
				fill.addEventListener('click', function () {
					document.querySelectorAll('.fn-pr-initials').forEach(function (i) {
						var row = i.closest('tr');
						var touched = row && Array.prototype.some.call(row.querySelectorAll('input[type=time], input[type=number]'), function (x) { return x.value !== ''; });
						if (!i.value && touched) { i.value = mine; }
					});
				});
			}
			var form = document.getElementById('fn-pr-form');
			var add = document.getElementById('fn-pr-add-batch');
			if (add && form) {
				add.addEventListener('click', function () {
					document.getElementById('fn_action').value = 'add_batch';
					form.submit();
				});
			}
			document.querySelectorAll('.fn-pr-delete').forEach(function (b) {
				b.addEventListener('click', function () {
					document.getElementById('fn_action').value = 'delete_batch';
					document.getElementById('fn_delete_code').value = b.getAttribute('data-code');
					form.submit();
				});
			});
		})();
		</script>
		<?php
	}
}

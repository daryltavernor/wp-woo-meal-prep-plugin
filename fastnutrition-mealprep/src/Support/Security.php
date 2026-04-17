<?php
/**
 * Nonce + capability helpers used throughout admin & REST.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Support;

final class Security {

	public const NONCE_ADMIN = 'fn_mealprep_admin';
	public const NONCE_REST  = 'fn_mealprep_rest';

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function can_view_prep(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
	}

	public static function require_manage(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fastnutrition-mealprep' ), '', [ 'response' => 403 ] );
		}
	}

	public static function verify_admin_nonce( string $action = self::NONCE_ADMIN ): void {
		$nonce = isset( $_REQUEST['_fn_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_fn_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fastnutrition-mealprep' ), '', [ 'response' => 403 ] );
		}
	}
}

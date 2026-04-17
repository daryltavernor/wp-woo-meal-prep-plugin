<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\ProfileResolver;

final class ConflictsNotice {

	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	public function add_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'fn_delivery_conflicts',
			__( 'Meal Prep — Delivery Profile Conflicts', 'fastnutrition-mealprep' ),
			[ $this, 'render_widget' ]
		);
	}

	public function render_widget(): void {
		$conflicts = ProfileResolver::conflicts();
		if ( empty( $conflicts['overlaps'] ) && empty( $conflicts['zones_without_profile'] ) ) {
			echo '<p>' . esc_html__( 'No delivery profile conflicts detected.', 'fastnutrition-mealprep' ) . '</p>';
			return;
		}
		if ( ! empty( $conflicts['overlaps'] ) ) {
			echo '<h4>' . esc_html__( 'Overlapping postcodes', 'fastnutrition-mealprep' ) . '</h4><ul>';
			foreach ( $conflicts['overlaps'] as $conflict ) {
				printf(
					'<li><code>%s</code> — %s</li>',
					esc_html( $conflict['postcode'] ),
					esc_html( implode( ', ', array_map( 'strval', $conflict['profile_ids'] ) ) )
				);
			}
			echo '</ul>';
		}
		if ( ! empty( $conflicts['zones_without_profile'] ) ) {
			echo '<h4>' . esc_html__( 'Shipping zones without a profile', 'fastnutrition-mealprep' ) . '</h4><ul>';
			foreach ( $conflicts['zones_without_profile'] as $zone ) {
				echo '<li>' . esc_html( (string) $zone ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'dashboard' === $screen->id ) {
			return;
		}
		if ( ! str_contains( (string) ( $screen->id ?? '' ), 'fn-' ) ) {
			return;
		}
		$conflicts = ProfileResolver::conflicts();
		if ( empty( $conflicts['overlaps'] ) && empty( $conflicts['zones_without_profile'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Delivery profile conflicts detected — see the Meal Prep dashboard widget for details.', 'fastnutrition-mealprep' ) . '</p></div>';
	}
}

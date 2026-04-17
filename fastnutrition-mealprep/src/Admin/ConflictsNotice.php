<?php
/**
 * Surfaces profile conflicts / uncovered shipping postcodes as an admin notice + dashboard widget.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

use FastNutrition\MealPrep\Delivery\ProfileResolver;
use FastNutrition\MealPrep\Support\Security;

final class ConflictsNotice {

	public function __construct( private readonly ProfileResolver $resolver ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
	}

	public function render_notice(): void {
		if ( ! Security::can_manage() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'woocommerce_page_fn-profiles' ], true ) ) {
			return;
		}
		$summary = $this->summary();
		if ( 0 === $summary['conflicts'] && 0 === $summary['uncovered'] ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: 1: conflicts count, 2: uncovered count, 3: profiles url */
			esc_html__( 'Meal Prep: %1$d profile conflict(s) and %2$d uncovered shipping postcode(s). %3$s', 'fastnutrition-mealprep' ),
			(int) $summary['conflicts'],
			(int) $summary['uncovered'],
			'<a href="' . esc_url( admin_url( 'admin.php?page=fn-profiles' ) ) . '">' . esc_html__( 'Review profiles', 'fastnutrition-mealprep' ) . '</a>'
		);
		echo '</p></div>';
	}

	public function register_widget(): void {
		if ( ! Security::can_manage() ) {
			return;
		}
		wp_add_dashboard_widget( 'fn_mealprep_widget', __( 'Meal Prep — Profile health', 'fastnutrition-mealprep' ), [ $this, 'render_widget' ] );
	}

	public function render_widget(): void {
		$conflicts = $this->resolver->conflicts();
		$uncovered = $this->resolver->uncovered_shipping_postcodes();
		echo '<h4>' . esc_html__( 'Conflicting postcodes', 'fastnutrition-mealprep' ) . '</h4>';
		if ( empty( $conflicts ) ) {
			echo '<p>' . esc_html__( 'None. ✓', 'fastnutrition-mealprep' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $conflicts as $c ) {
				$names = array_column( $c['profiles'], 'name' );
				echo '<li>' . esc_html( $c['pattern'] . ' (' . $c['method'] . ') → ' . implode( ', ', $names ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<h4>' . esc_html__( 'Shipping postcodes without a profile', 'fastnutrition-mealprep' ) . '</h4>';
		if ( empty( $uncovered ) ) {
			echo '<p>' . esc_html__( 'None. ✓', 'fastnutrition-mealprep' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $uncovered as $u ) {
				echo '<li>' . esc_html( $u['zone'] . ' — ' . $u['pattern'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function summary(): array {
		return [
			'conflicts' => count( $this->resolver->conflicts() ),
			'uncovered' => count( $this->resolver->uncovered_shipping_postcodes() ),
		];
	}
}

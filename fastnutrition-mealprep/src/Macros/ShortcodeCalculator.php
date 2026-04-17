<?php
/**
 * [fn_macro_calculator] shortcode + front-end mount. Renders an empty container; the React app fetches
 * ingredients from the REST API and manages state client-side.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

final class ShortcodeCalculator {

	public const SHORTCODE = 'fn_macro_calculator';

	public function register(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
	}

	public function render( $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'allow_custom' => 'yes',
				'show_targets' => 'yes',
			],
			is_array( $atts ) ? $atts : []
		);
		wp_enqueue_script( 'fn-macro-calculator' );
		wp_enqueue_style( 'fn-front' );

		return sprintf(
			'<div class="fn-macro-calculator" data-allow-custom="%s" data-show-targets="%s"></div>',
			esc_attr( 'yes' === $atts['allow_custom'] ? '1' : '0' ),
			esc_attr( 'yes' === $atts['show_targets'] ? '1' : '0' )
		);
	}
}

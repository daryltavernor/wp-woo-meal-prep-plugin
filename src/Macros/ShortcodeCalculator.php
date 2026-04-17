<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

final class ShortcodeCalculator {

	public const SHORTCODE = 'fn_macro_calculator';

	public function register(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
	}

	public function render( array|string $atts = [], ?string $content = null ): string {
		wp_enqueue_script( 'fn-macro-calculator' );
		wp_enqueue_style( 'fn-macro-calculator' );
		$target = 'fn-macro-calc-' . wp_generate_uuid4();
		return sprintf(
			'<div class="fn-macro-calc" id="%1$s" data-fn-macro-calc="1"></div>',
			esc_attr( $target )
		);
	}
}

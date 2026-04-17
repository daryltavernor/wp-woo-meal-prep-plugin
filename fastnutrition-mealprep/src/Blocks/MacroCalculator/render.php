<?php
/**
 * Renders the mount point for the macro calculator React app.
 *
 * @var array $attributes
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

$allow_custom = ! empty( $attributes['allowCustom'] ) ? '1' : '0';
$show_targets = ! empty( $attributes['showTargets'] ) ? '1' : '0';
?>
<div class="fn-macro-calculator"
	data-allow-custom="<?php echo esc_attr( $allow_custom ); ?>"
	data-show-targets="<?php echo esc_attr( $show_targets ); ?>">
	<noscript><?php esc_html_e( 'Please enable JavaScript to use the macro calculator.', 'fastnutrition-mealprep' ); ?></noscript>
</div>

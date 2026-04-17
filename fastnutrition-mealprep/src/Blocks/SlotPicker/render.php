<?php
/**
 * Mount point for the slot-picker React app. Reads the postcode from the checkout Store API
 * and calls /fastnutrition/v1/slots.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );
?>
<div class="fn-slot-picker">
	<noscript><?php esc_html_e( 'Please enable JavaScript to select a delivery or collection slot.', 'fastnutrition-mealprep' ); ?></noscript>
</div>

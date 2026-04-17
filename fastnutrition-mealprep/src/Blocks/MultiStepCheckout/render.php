<?php
/**
 * Wrapper for the multi-step checkout UI. Inserts a container so the React controller can toggle steps
 * via CSS classes. Actual step markup is still produced by WooCommerce's own Checkout inner blocks.
 *
 * @var array $attributes
 * @var string $content
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );
?>
<div class="fn-multistep-checkout" data-steps="3">
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>

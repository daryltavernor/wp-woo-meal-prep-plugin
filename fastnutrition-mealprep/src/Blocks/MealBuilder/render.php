<?php
/**
 * Server-rendered markup for the Meal Builder block. The React view-script hydrates this container.
 *
 * @var array  $attributes
 * @var string $content
 * @var \WP_Block $block
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\MealProduct;

$product_id = 0;
if ( ! empty( $attributes['productId'] ) ) {
	$product_id = (int) $attributes['productId'];
}
if ( 0 === $product_id ) {
	global $product;
	if ( $product instanceof WC_Product ) {
		$product_id = $product->get_id();
	} elseif ( is_singular( 'product' ) ) {
		$product_id = get_the_ID();
	}
}

if ( 0 === $product_id || ! MealProduct::is_meal( $product_id ) ) {
	return;
}

$config  = MealProduct::config( $product_id );
$addons  = AddOnMeta::get( $product_id );
$payload = [
	'productId' => $product_id,
	'config'    => $config,
	'addons'    => $addons,
];
?>
<div class="fn-meal-builder" data-product="<?php echo (int) $product_id; ?>" data-config='<?php echo esc_attr( wp_json_encode( $payload ) ); ?>'>
	<noscript><?php esc_html_e( 'Please enable JavaScript to build your meal.', 'fastnutrition-mealprep' ); ?></noscript>
</div>

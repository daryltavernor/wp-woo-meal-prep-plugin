<?php
/**
 * AddOns are priced in Selections::recalculate_price() together with ingredient price_delta, so this class
 * only exists to hook in validation / display touches that don't belong in Selections.
 *
 * Kept as a thin class so the DI wiring in Plugin.php stays consistent with the plan document.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Cart;

final class AddOnPricer {

	public function register(): void {
		// Reserved: additional tax handling / per-addon coupons would hook here later.
	}
}

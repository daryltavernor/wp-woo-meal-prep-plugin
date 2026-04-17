<?php
/**
 * Thin controller class. Bundle config is fully handled by BundleMeta on the product screen,
 * so this class exposes nothing extra beyond a global summary screen (future iteration).
 *
 * Retained per the plan's directory layout; ready for a "bundle overview" screen later.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Admin;

final class BundleAdmin {

	public function register(): void {
		// Nothing yet — product-level UI covers current scope.
	}
}

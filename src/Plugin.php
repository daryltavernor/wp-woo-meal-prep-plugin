<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep;

use FastNutrition\MealPrep\Account\Favourites;
use FastNutrition\MealPrep\Admin\BlockedDatesAdmin;
use FastNutrition\MealPrep\Admin\BundleAdmin;
use FastNutrition\MealPrep\Admin\ConflictsNotice;
use FastNutrition\MealPrep\Admin\LabelsAdmin;
use FastNutrition\MealPrep\Admin\MenuRegistry;
use FastNutrition\MealPrep\Admin\OrdersListBulkActions;
use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\PrepSheet;
use FastNutrition\MealPrep\Admin\ProfileAdmin;
use FastNutrition\MealPrep\Admin\SettingsPage;
use FastNutrition\MealPrep\Cart\AddOnPricer;
use FastNutrition\MealPrep\Cart\BundlePricer;
use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Cart\Selections;
use FastNutrition\MealPrep\Cart\Surcharge;
use FastNutrition\MealPrep\Cart\TotalsDisplay;
use FastNutrition\MealPrep\Checkout\MultiStep;
use FastNutrition\MealPrep\Checkout\StoreApiExtensions;
use FastNutrition\MealPrep\Delivery\BlockedDates;
use FastNutrition\MealPrep\Delivery\Profile;
use FastNutrition\MealPrep\Delivery\ProfileResolver;
use FastNutrition\MealPrep\Delivery\SlotAvailability;
use FastNutrition\MealPrep\Macros\Calculator;
use FastNutrition\MealPrep\Macros\CustomIngredientStore;
use FastNutrition\MealPrep\Macros\ShortcodeCalculator;
use FastNutrition\MealPrep\PostTypes\Ingredient;
use FastNutrition\MealPrep\Products\AddOnMeta;
use FastNutrition\MealPrep\Products\BundleMeta;
use FastNutrition\MealPrep\Products\MealProduct;
use FastNutrition\MealPrep\Rest\RestController;
use FastNutrition\MealPrep\Support\AssetManager;
use FastNutrition\MealPrep\Taxonomies\Allergen;
use FastNutrition\MealPrep\Taxonomies\IngredientType;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'fastnutrition-mealprep', false, dirname( plugin_basename( FN_MEALPREP_FILE ) ) . '/languages' );

		( new IngredientType() )->register();
		( new Allergen() )->register();
		( new Ingredient() )->register();

		( new MealProduct() )->register();
		( new AddOnMeta() )->register();
		( new BundleMeta() )->register();

		( new Selections() )->register();
		( new BundlePricer() )->register();
		( new AddOnPricer() )->register();
		( new OrderItemMeta() )->register();
		( new TotalsDisplay() )->register();
		( new Surcharge() )->register();

		( new Calculator() )->register();
		( new ShortcodeCalculator() )->register();
		( new CustomIngredientStore() )->register();

		( new Profile() )->register();
		( new ProfileResolver() )->register();
		( new BlockedDates() )->register();
		( new SlotAvailability() )->register();

		( new MultiStep() )->register();
		( new StoreApiExtensions() )->register();

		( new MenuRegistry() )->register();
		( new SettingsPage() )->register();
		( new PrepDashboard() )->register();
		( new PrepSheet() )->register();
		( new ProfileAdmin() )->register();
		( new BlockedDatesAdmin() )->register();
		( new BundleAdmin() )->register();
		( new ConflictsNotice() )->register();
		( new LabelsAdmin() )->register();
		( new OrdersListBulkActions() )->register();

		( new Favourites() )->register();
		( new RestController() )->register();
		( new AssetManager() )->register();
	}
}

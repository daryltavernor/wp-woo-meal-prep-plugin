<?php
/**
 * Core plugin container. Instantiates and boots subsystems.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep;

use FastNutrition\MealPrep\Account\Favourites;
use FastNutrition\MealPrep\Admin\BlockedDatesAdmin;
use FastNutrition\MealPrep\Admin\BundleAdmin;
use FastNutrition\MealPrep\Admin\ConflictsNotice;
use FastNutrition\MealPrep\Admin\MenuRegistry;
use FastNutrition\MealPrep\Admin\PrepDashboard;
use FastNutrition\MealPrep\Admin\PrepSheet;
use FastNutrition\MealPrep\Admin\ProfileAdmin;
use FastNutrition\MealPrep\Blocks\BlockRegistry;
use FastNutrition\MealPrep\Cart\AddOnPricer;
use FastNutrition\MealPrep\Cart\BundlePricer;
use FastNutrition\MealPrep\Cart\OrderItemMeta;
use FastNutrition\MealPrep\Cart\Selections;
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

	/** @var array<string,object> */
	private array $services = [];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		$this->services['ingredient_type'] = new IngredientType();
		$this->services['allergen']        = new Allergen();
		$this->services['ingredient']      = new Ingredient();

		$this->services['meal_product']    = new MealProduct();
		$this->services['addon_meta']      = new AddOnMeta();
		$this->services['bundle_meta']     = new BundleMeta();

		$this->services['selections']      = new Selections();
		$this->services['bundle_pricer']   = new BundlePricer();
		$this->services['addon_pricer']    = new AddOnPricer();
		$this->services['order_item_meta'] = new OrderItemMeta();

		$this->services['macros_calc']     = new Calculator();
		$this->services['macros_short']    = new ShortcodeCalculator();
		$this->services['macros_custom']   = new CustomIngredientStore();

		$this->services['delivery_profile']  = new Profile();
		$this->services['profile_resolver']  = new ProfileResolver();
		$this->services['blocked_dates']     = new BlockedDates();
		$this->services['slot_availability'] = new SlotAvailability(
			$this->services['profile_resolver'],
			$this->services['blocked_dates']
		);

		$this->services['checkout_multistep'] = new MultiStep();
		$this->services['store_api_ext']      = new StoreApiExtensions( $this->services['slot_availability'] );

		$this->services['menu']             = new MenuRegistry();
		$this->services['prep_dashboard']   = new PrepDashboard();
		$this->services['prep_sheet']       = new PrepSheet();
		$this->services['profile_admin']    = new ProfileAdmin();
		$this->services['blocked_admin']    = new BlockedDatesAdmin();
		$this->services['bundle_admin']     = new BundleAdmin();
		$this->services['conflicts_notice'] = new ConflictsNotice( $this->services['profile_resolver'] );

		$this->services['favourites']   = new Favourites();
		$this->services['rest']         = new RestController( $this->services['slot_availability'] );
		$this->services['assets']       = new AssetManager();
		$this->services['blocks']       = new BlockRegistry();

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}
	}

	public function service( string $key ): ?object {
		return $this->services[ $key ] ?? null;
	}
}

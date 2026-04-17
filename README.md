# Fast Nutrition — Meal Prep (WooCommerce Plugin)

A full meal-prep ordering system for the Fast Nutrition website, built on the latest WooCommerce + WordPress Blocks stack. It lets customers build meals (Protein + Carb + Greens, or two greens instead of a carb, or a pre-made set meal), attaches macros to every selection, handles quantity bundles and per-line-item add-ons, collects delivery / collection preferences via postcode-aware profiles, and gives kitchen staff a per-day prep dashboard and a printable prep sheet.

---

## Requirements

| Component | Minimum |
|-----------|---------|
| WordPress | 6.6 |
| WooCommerce | 9.4 (HPOS + Cart/Checkout Blocks enabled) |
| PHP | 8.1 |
| Node | 20 (for building JS assets) |
| Composer | 2.x |

---

## Installation

The repository ships with `vendor/` (composer dependencies) and `assets/build/` (compiled blocks) already built, so no build step is required for production use.

1. **Place the plugin** in your WP install:
   ```
   wp-content/plugins/fastnutrition-mealprep/
   ```
2. **Activate the plugin** in WP Admin → Plugins. Activation creates three DB tables (`fn_delivery_profiles`, `fn_blocked_dates`, `fn_prep_cache`) and seeds the Ingredient Type and Allergen taxonomies.

That's it. If you're developing on the plugin and need to rebuild assets, see the **Development** section below.

### Development (only if you're changing the code)

```bash
composer install                  # dev deps incl. PHPUnit + PHPCS
npm install                       # JS deps
npm run build                     # one-off build
npm run start                     # watch mode
```

### Development scripts

```bash
npm run start        # watch mode for JS/CSS
npm run lint:js      # ESLint via @wordpress/scripts
composer test        # run PHPUnit
composer lint        # run PHPCS against WordPress + WooCommerce standards
```

---

## Post-install checklist

1. **WooCommerce → Meal Prep** appears in the admin menu. Check the sub-pages: *Prep Dashboard*, *Prep Sheet*, *Delivery Profiles*, *Blocked Dates*.
2. Open **Products → Ingredients** (injected under the Products menu) and create Proteins, Carbs, Greens, and Set Meals. Assign macros and an optional price modifier to each.
3. Edit (or create) a **simple Woo product** — new tabs/meta boxes appear:
   * **Meal Builder** tab — enable the builder, choose tier (standard/bulk), allow double greens, allow set meal mode, restrict allowed ingredients.
   * **Meal Add-ons** meta box — per-meal line-item add-ons (e.g. *+£1 boiled eggs*).
   * **Quantity Bundles** meta box — e.g. `10 / £35`, `15 / £50`. Bundles only apply to this specific product ID.
4. Create **Delivery Profiles** under Meal Prep → Delivery Profiles:
   * Name, Delivery or Collection, postcode list (one per line, wildcards like `ST10*` or prefix match `ST10`), day-of-week checkboxes, timed slots with optional capacity.
5. Block any one-off dates under **Meal Prep → Blocked Dates**.
6. Open the **Checkout** page and replace the default *Checkout* block with **Fast Nutrition Multi-step Checkout**. Inside the Checkout inner block, insert the **Delivery / Collection Slot Picker** block where you want the slot chooser to appear (typically between Shipping Address and Payment).
7. Add the **Macro Calculator** block or the `[fn_macro_calculator]` shortcode to any page you like.
8. Customers with an account get a **Favourites** tab under *My Account* to save and reorder meal combos.

---

## Feature overview

### Meal builder (on product pages)
When a simple product has "Enable meal builder" ticked, the default Add-to-Cart button is replaced with the builder UI:
* **Build mode**: pick a Protein, Carb, Greens. Toggle "double greens" to swap the carb for a 2nd greens.
* **Set meal mode** (if enabled): pick a pre-made set meal.
* Per-line add-ons (e.g. *boiled eggs +£1*).
* Live macro total.
* Server-side validation rejects disallowed combinations.

### Bundle pricing
Per-product quantity tiers (e.g. `10 for £35`). Applies only to matching product IDs so desserts and other non-bundle products are untouched. The largest tier that fits is chosen; remaining qty is priced at base. Each cart line shows an effective per-meal price (e.g. *10 for £35 (~£3.50 each)*).

### Add-ons
Per-line-item. Each meal instance in the cart carries its own add-on selection and the price flows through to the order and emails.

### Delivery / Collection profiles
A profile groups postcodes with the days and time windows you cover. Collection profiles ignore postcodes. The dashboard widget flags two conflict types:
* Postcodes that appear in more than one delivery profile (resolved by priority).
* WooCommerce shipping zones whose postcodes are not covered by any profile.

### Blocked dates
Site-wide dates with an optional reason. Blocked dates disable every slot regardless of method or postcode.

### Multi-step Blocks checkout
`fastnutrition/multi-step-checkout` wraps the native Woo Checkout block and presents 3 steps with nav buttons:
1. **Your details** (contact + shipping address)
2. **Delivery or collection** (postcode-aware slot picker)
3. **Payment** (payment methods + order summary)

The slot is persisted to the order via the Store API `extensionCartUpdate` — `_fn_fulfilment` is written on the order when it is placed.

### Macros
Every ingredient carries `{kcal, protein_g, carbs_g, fat_g, fibre_g}`. At order time the snapshot is stored on each line item (so edits to ingredients later never change past orders) and the totals are printed at the bottom of the customer email.

### Macro calculator
Block: `fastnutrition/macro-calculator` · shortcode: `[fn_macro_calculator]`.
* Pulls ingredients from the REST API.
* "Add custom ingredient" creates a locally-stored (and optionally account-synced) entry.
* Optional daily-target progress bar.

### Kitchen prep dashboard
WooCommerce → Meal Prep → **Prep Dashboard**.
* Filter by fulfilment date.
* **By day** view — aggregated ingredient totals ("15 portions of asparagus needed").
* **By order** view — table of orders with their individual selections.
* Rebuilt on order status change, backed by `fn_prep_cache` for fast dashboard loads.

### Printable kitchen prep sheet
WooCommerce → Meal Prep → **Prep Sheet**.
Three sections: Ingredient totals · Per-order pick list (with tick-off checkboxes) · Delivery run sheet grouped by profile and postcode. Print via the browser or download as a server-side PDF (Dompdf).

### Customer favourites
Logged-in customers can save meal combos to their account and re-add them with a single click from *My Account → Favourites*.

---

## REST endpoints (namespace `fastnutrition/v1`)

| Method | Route | Purpose |
|--------|-------|---------|
| `GET`  | `/ingredients[?type=protein|carb|greens|set_meal]` | Lists ingredients with macros, allergens, price delta. |
| `GET`  | `/meal-config/{product_id}` | Returns meal builder config, add-ons, bundles, base price. |
| `GET`  | `/slots?postcode=...&method=delivery|collection` | Returns available {date, slot} options for the next 14 days. |
| `GET/POST` | `/custom-ingredients` | Logged-in users' custom macro-calculator ingredients. |
| `GET/POST/DELETE` | `/favourites` | Manages saved meal combos. |

Store API extensions (namespace `fastnutrition-mealprep`):
* Cart endpoint: `extensions.fastnutrition-mealprep.macros` — running macro totals.
* Checkout endpoint: `extensions.fastnutrition-mealprep.fulfilment` — selected slot.
* Update callback: `set_fulfilment` — writes the slot to the WC session.

---

## Directory layout

```
fastnutrition-mealprep/
  fastnutrition-mealprep.php      # bootstrap, HPOS + Blocks compatibility
  composer.json                   # PSR-4 autoload + Dompdf
  package.json                    # @wordpress/scripts build
  src/
    Plugin.php
    Install/Activator.php
    PostTypes/Ingredient.php
    Taxonomies/IngredientType.php
    Taxonomies/Allergen.php
    Products/{MealProduct,AddOnMeta,BundleMeta}.php
    Cart/{Selections,BundlePricer,AddOnPricer,OrderItemMeta}.php
    Macros/{Calculator,ShortcodeCalculator,CustomIngredientStore}.php
    Delivery/{Profile,ProfileResolver,BlockedDates,SlotAvailability}.php
    Checkout/{MultiStep,StoreApiExtensions}.php
    Admin/{MenuRegistry,PrepDashboard,PrepSheet,ProfileAdmin,BlockedDatesAdmin,BundleAdmin,ConflictsNotice}.php
    Account/Favourites.php
    Rest/RestController.php
    Support/AssetManager.php
  assets/src/blocks/
    meal-builder/
    macro-calculator/
    multi-step-checkout/
    slot-picker/
  assets/build/                   # produced by `npm run build`
```

---

## Security notes

* Every admin action is capability-checked (`manage_woocommerce`) and nonce-verified.
* Customer-facing REST endpoints use `is_user_logged_in()` where writes are involved; public reads are limited to data that is already rendered on the shop.
* Order item selections are re-validated server-side on add-to-cart — no client-only trust.
* The ingredient CPT is registered as `public => false` so it never appears in sitemaps or search.

---

## Known limitations / next steps

* The multi-step checkout navigation is implemented client-side by toggling classes. If your theme adds additional Woo blocks inside the checkout fields block, you can extend the `STEPS` array in `assets/src/blocks/multi-step-checkout/view.js`.
* Slot capacity is counted against paid/processing/on-hold orders. If you need to count abandoned checkouts too, extend `SlotAvailability::count_booked()`.
* The Favourites "Save" button is exposed via REST; the product page UI currently adds a line in the builder via the block script (customize as needed).
* Translation: a `languages/` directory is provided; generate a `.pot` with `wp i18n make-pot . languages/fastnutrition-mealprep.pot`.

---

## License

GPL-2.0-or-later — same as WooCommerce and WordPress.

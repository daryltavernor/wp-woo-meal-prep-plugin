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

Everything lives under the **Meal Prep** top-level menu in WP admin (the only per-product settings are the three meta boxes/tab on the Edit Product screen, which is the right place for them).

1. **Meal Prep → Ingredients** — create Proteins, Carbs, Greens, and **Set Meals**. Set meals are complete pre-made meals; they use the same Ingredient screen with **Ingredient Type = Set Meal** (see the sidebar taxonomy on the edit screen). A "Set Meals" shortcut submenu filters this list to just set meals. Each ingredient carries macros and an optional price modifier.
2. **Meal Prep → Ingredient Types / Allergens** — manage the taxonomy terms if you want to add e.g. a "Nut Free" diet tag.
3. **Meal Prep → Delivery Profiles** — create profiles (a group of postcodes + days of the week + time windows). Delivery profiles match by postcode; collection profiles apply to everyone. The dashboard widget flags postcode overlaps and WC shipping zones that aren't covered by any profile.
4. **Meal Prep → Blocked Dates** — one-off closures (Christmas, training days). Disables every slot on every profile for that date.
5. **Meal Prep → Settings** — toggle the multi-step checkout, convert a legacy shortcode checkout page to Blocks in one click, or auto-create a Macro Calculator page.
6. **Meal Prep → Prep Dashboard / Prep Sheet** — daily kitchen views.
7. **Edit Product** (any simple Woo product) — the extras live on the product itself because they're product-specific:
   * **Meal Builder** tab — enable the builder, choose tier (standard/bulk), allow double greens, allow set meal mode, restrict allowed ingredients.
   * **Standalone Product** tab — sell a single pre-made item directly (a **Set Meal** or a **Sweet**) instead of building a meal. Pick the type, then tick one or more items: one item = no choice for the customer, several = a dropdown on the product page. Used for sweets, individual set-meal special offers, and any single-item product.
   * **Meal Add-ons** meta box — per-line-item extras (e.g. *+£1 boiled eggs*).
   * **Quantity Bundles** meta box — e.g. `10 / £35`, `15 / £50`. Applies only to this product ID.

### Where do I add Set Meals?
Meal Prep → **Ingredients → Add Ingredient**. In the right-hand sidebar, under **Ingredient Type**, tick **Set Meal**. Set the macros for the complete meal and (optionally) a price modifier. Then on each meal product that should offer set meals, tick **Allow set meal mode** in the Meal Builder tab and select which set meals are allowed.

### Selling sweets, single meals & special offers (Standalone Products)
Sweets and other single, pre-made items aren't built from components, so they live **outside** the meal builder. On the product, open the **Standalone Product** tab, enable it, choose **Item type** (Set Meal or Sweet) and tick the item(s) to offer — one for a fixed product (no customer choice) or several to show a dropdown. Standalone products still flow through macros, the prep dashboard/sheet, meal labels, the Quick Order screen and the Quick Label Maker exactly like meal-builder products.

> **Upgrading from 1.11 or earlier:** the meal builder's old *"Sweets product (Sweet mode)"* option has been removed and sweets decoupled from the builder. Re-create those products using the **Standalone Product** tab (Item type = Sweet). Existing orders are unaffected — their labels and prep data still render.

## Checkout — how it works now

You **do not** need to edit the checkout page or drop blocks into it. As long as the checkout page uses the standard WooCommerce Checkout block (the default for fresh stores since WC 8.x), the multi-step flow applies automatically.

* If your checkout page still uses the old `[woocommerce_checkout]` **shortcode**, go to **Meal Prep → Settings** and click **Convert checkout page to Blocks**. That replaces the shortcode with the block and keeps a WP revision of the old content.
* If you'd rather keep the single-page Woo checkout, turn off **Enable multi-step checkout** under Settings.
* The slot picker is **auto-injected** between the shipping address and payment blocks — no manual block placement required.

### Third-party checkout plugins
The multi-step flow is a **UI layer on top** of the native WC Checkout block — we don't remove or replace anything. Any plugin that registers its UI against the Checkout block (upsells, cross-sells, extra fields, order bumps) will continue to render. Unknown blocks fall into **Step 1 (Your details)** by default. If a third-party plugin's UI needs to appear during Payment, add its CSS selector to the `STEPS[2].matchers` array in `assets/src/blocks/multi-step-checkout/view.js` and rebuild.

## Do I need to create any pages?

No. The plugin uses the existing WooCommerce **Cart** and **Checkout** pages. If you want a dedicated Macro Calculator page, the Settings screen has a one-click button to create one; otherwise the `[fn_macro_calculator]` shortcode (or the Macro Calculator block) works on any page/post. Logged-in customers automatically get a **Favourites** tab added to their My Account page.

---

## Feature overview

### Meal builder (on product pages)
When a simple product has "Enable meal builder" ticked, the default Add-to-Cart button is replaced with the builder UI:
* **Build mode**: pick a Protein, Carb, Greens. Toggle "double greens" to swap the carb for a 2nd greens.
* **Set meal mode** (if enabled): pick a pre-made set meal.
* Per-line add-ons (e.g. *boiled eggs +£1*).
* Live macro total.
* Server-side validation rejects disallowed combinations.

### Standalone products (on product pages)
When a product has the **Standalone Product** tab enabled, the page sells the chosen item directly instead of showing the builder: a single configured item adds straight to the basket, while two or more render a dropdown (same styling as the builder). Sweets, individual set meals and special offers are sold this way, and still carry full macros, prep and label data.

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

### Quick Order (staff / phone orders)
A fast, touch-first screen (works well on an iPad) that lets staff take phone and walk-in orders straight into WooCommerce — no checkout flow. It creates a **real** WooCommerce order that appears in *WooCommerce → Orders* exactly like an online one, reusing the same line pricing, bundle tiers, meal-composition meta and `_fn_fulfilment` slot data, so kitchen prep lists and reporting never fork.

* **Open it:** *Meal Prep → Quick Order* in the WordPress admin. It is a normal admin page, gated to **Shop Managers and Administrators** (the `manage_woocommerce` capability) just like the plugin's other settings. The order is attributed to whoever is signed in — no separate password or PIN.
* **Flow:** Step 1 build food (one tab per enabled product — the meal builder for meal products, or an item picker for standalone products — with one optional add-on and quantity on every line) with instant add-and-reset; Step 2 contact + delivery/collection slot + payment + paid/unpaid; Step 3 review + submit.
* **Order tagging + filtering:** every offline order carries `created_via = fn_instore`, `_fn_offline_order = yes`, and `_fn_staff_name` / `_fn_staff_id` (the signed-in user). In *WooCommerce → Orders* a **Source** column shows an **In-store** badge on offline orders (online orders are untouched and show nothing), and a toolbar dropdown filters the list to **In-store / Online / All**.
* **Pricing parity:** identical line pricing **and bundle/quantity tiers** to online; adds the delivery fee for delivery orders, does **not** add the basket surcharge (see `CHECKOUT_PRICING.md`). Paid → **Completed**, Unpaid → **On hold** (stock is reduced as normal). Macros are stored on each line item, so the printed meal labels include them just like online orders.
* **Confirmation email:** when an email address is entered and the toggle is on, the customer is sent WooCommerce's order-details ("invoice") email. Delivery requires a working mail transport on the site — e.g. the **Post SMTP** plugin configured with your provider; the plugin only hands the message to `wp_mail()`.
* **Settings:** *Meal Prep → Quick Order Settings* — tick which products appear in the Quick Order screen and which appear in the Quick Label Maker (any product with the Meal Builder or a Standalone Product enabled is listed; the two lists are independent), plus the confirmation-email default.

### Quick Label Maker
*Meal Prep → Quick Label Maker* is the same touch screen, but on submit it produces a **labels PDF** (opened in a new tab to print). By default it creates **no WooCommerce order**. The flow is identical to Quick Order — build the meals, enter the customer name (required) + optional phone, pick collection/delivery + slot, and set paid / not-paid + method (this prints on the label) — minus the customer-email option. Labels are byte-identical to an order's labels (summary + one label per meal, with macros and USE BY). Phone is optional on both the Quick Order and Quick Label Maker screens.

* **Also add to prep sheet (opt-in):** tick this on the review step to have the batch also feed the kitchen. It creates a real order in a dedicated **"Prep / label only"** status that is **excluded from sales/analytics**, reduces no stock and sends no emails — so the meals show up in the prep dashboard, prep sheet, ingredient totals and slot capacity, while never counting as revenue. These orders appear in *WooCommerce → Orders* with an amber **Label/Prep** badge.

### Order cut-off (in-store vs website)
The public website honours the **order cut-off** time set under *Meal Prep → Settings* (default 18:00): after it, the earliest fulfilment date rolls forward a day. The **Quick Order** and **Quick Label Maker** tools use a relaxed **23:55** cut-off instead, so staff can still book tomorrow when taking orders in the evening. The one-day lead time still applies.

### Sweet labels
Sweets have variable shelf lives (made fresh or weeks ahead), so on a **sweet** meal label the **USE BY** line is printed **blank** for staff to date by hand, and the cook/reheat/storage guidance is omitted (the allergen-info pointer is kept). All other labels — built meals and set meals — are unchanged, with the auto-calculated USE BY and full storage line.

---

## REST endpoints (namespace `fastnutrition/v1`)

| Method | Route | Purpose |
|--------|-------|---------|
| `GET`  | `/ingredients[?type=protein|carb|greens|set_meal]` | Lists ingredients with macros, allergens, price delta. |
| `GET`  | `/meal-config/{product_id}` | Returns meal builder config, add-ons, bundles, base price. |
| `GET`  | `/slots?postcode=...&method=delivery|collection` | Returns available {date, slot} options for the next 14 days. |
| `GET/POST` | `/custom-ingredients` | Logged-in users' custom macro-calculator ingredients. |
| `GET/POST/DELETE` | `/favourites` | Manages saved meal combos. |
| `GET`  | `/instore/config?mode=order|label` | Quick Order/Label screen hydration: enabled products, payment methods, currency. *Requires `manage_woocommerce`.* |
| `GET`  | `/instore/slots?postcode=...&method=...` | Same as `/slots` but with the relaxed in-store 23:55 cut-off. *Requires `manage_woocommerce`.* |
| `POST` | `/instore/order` | Creates a WooCommerce order from the screen's basket via `InStore\OrderFactory`, attributed to the current user. *Requires `manage_woocommerce`.* |
| `POST` | `/instore/labels` | Streams a labels PDF; with `add_to_prep`, first persists a non-sales "Prep / label only" order. *Requires `manage_woocommerce`.* |

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
    InStore/{OrderFactory,InStoreSettings,QuickOrderRest,QuickOrderPage}.php
    Cart/MealPricing.php            # shared online/offline line-pricing core
    Support/AssetManager.php
  assets/src/blocks/
    meal-builder/
    macro-calculator/
    multi-step-checkout/
    slot-picker/
  assets/src/quick-order/         # In-Store Quick Order touch app (non-block entry)
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

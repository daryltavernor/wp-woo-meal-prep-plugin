# Fast Nutrition — Meal Prep

Custom WooCommerce plugin for Fast Nutrition: meal builder (protein + carb + greens, double-greens swap, set meals), bundle pricing, per-line add-ons, delivery/collection profiles with a multi-step block checkout, and a kitchen prep dashboard with printable prep sheets.

## Requirements

- PHP 8.1+
- WordPress 6.6+
- WooCommerce 9.4+ (HPOS enabled)
- Cart & Checkout Blocks enabled
- Flatsome theme (styling tuned for this palette — black + `#c5e643`)

## Install

```bash
composer install
npm install
npm run build
```

Copy the `fastnutrition-mealprep/` directory into `wp-content/plugins/` and activate from WP admin. The activator creates three custom tables and seeds default options.

## Admin locations

- **WooCommerce → Meal Prep**: daily ingredient totals + per-order drill-down
- **WooCommerce → Prep Sheet**: printable A4 / PDF prep sheet for a given fulfilment date
- **WooCommerce → Delivery Profiles**: postcode + days + slot CRUD
- **WooCommerce → Blocked Dates**: block a date globally
- **Ingredients** (top-level CPT): proteins, carbs, greens and set meals with macros + allergens
- **Products → Meal Builder tab**: enable the builder on any simple product, pick allowed ingredients, enable double-greens / set-meal mode
- **Products → Add-ons tab**: per-product add-on lines
- **Products → Bundle Deals tab**: quantity-based bundle tiers (e.g. 10 × product #123 = £35)

## Front-end

- Meal builder block renders automatically on meal products
- Macro calculator via `[fn_macro_calculator]` shortcode or the Macro Calculator block
- Multi-step checkout via the "Fast Nutrition Multi-Step Checkout" block (insert inside the WooCommerce Checkout block)
- My Account → Favourite Meals — save cart combos for one-click reordering

## REST API

| Route | Method | Notes |
| --- | --- | --- |
| `/wp-json/fastnutrition/v1/ingredients?product=ID` | GET | Ingredients grouped by type, filtered by product allow-lists |
| `/wp-json/fastnutrition/v1/slots?postcode=X` | GET | Available `{method, date, slot}` for the postcode |
| `/wp-json/fastnutrition/v1/custom-ingredients` | GET/POST | Per-user custom macro-calculator ingredients |

Store API extension: `fastnutrition.fulfilment` on the Checkout endpoint carries the chosen slot.

## Tests

```bash
composer test  # PHPUnit unit tests
npm test       # Jest (wp-scripts)
```

## Project structure

See `src/` for the PHP classes, organised by domain (`PostTypes/`, `Products/`, `Cart/`, `Macros/`, `Delivery/`, `Checkout/`, `Admin/`, `Account/`, `Rest/`, `Support/`, `Blocks/`). Source JS/SCSS in `assets/src/`, compiled bundles written to `assets/build/` by `wp-scripts`.

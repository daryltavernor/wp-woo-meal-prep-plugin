# Checkout pricing pipeline

This document describes how the meal-prep plugin computes cart line prices,
totals, and the chosen WooCommerce shipping rate at checkout. It exists so
the next person to touch this code (or LLM session) can answer two questions
without re-reading every file: **where does this number come from**, and
**why doesn't it change when the cart recalculates twice?**

## Pipeline at a glance

```
  add to cart
      ↓
  Selections::attach_selection  → persists `fn_selection` meta on the cart item
      ↓
  cart.calculate_totals() fires
      ↓
  woocommerce_before_calculate_totals @ 10
      └─ BundlePricer::apply
         For each meal line:
           unit_base    = bundle_applies ? bundle.effective_unit : catalog
           selection_Δ  = Selections::compute_price_delta(pid, selection)
           item->set_price( unit_base + selection_Δ )         ← single source
      ↓
  woocommerce_cart_calculate_fees @ 10
      └─ Surcharge::maybe_add_fee
         if (basket subtotal < threshold) add the configured flat fee.
      ↓
  woocommerce_package_rates @ 100
      └─ StoreApiExtensions::filter_package_rates
         Removes rates that do not match the session fulfilment.type.
      ↓
  shipping rate cost is added to the total
      ↓
  total = subtotal + surcharge + shipping
```

## Layer-by-layer

### 1. `Selections` (`src/Cart/Selections.php`)

Owner of the per-line meal configuration. Writes a serializable array under
the cart item key `fn_selection` (constant `Selections::CART_KEY`) when the
customer adds a meal:

```
[
  mode       => 'build'|'set'|'sweet',
  protein_id, carb_id, greens_ids,    // build mode
  set_meal_id,                         // set mode
  sweet_id,                            // sweet mode
  addons     => [ { id, label, price, kcal, protein_g, ... } ],
  tier       => string
]
```

`compute_price_delta($product_id, $selection)` is the canonical function for
"how much does this selection cost above the catalog base?". It sums:

* `_fn_price_delta` on the chosen ingredient posts (protein, carb, greens)
  OR on the set-meal / sweet post,
* `addons[].price` for each chosen add-on.

The delta is **persisted in session state**. It does not depend on the
current cart item price.

### 2. `BundlePricer` (`src/Cart/BundlePricer.php`) — the single price mutator

Hooks `woocommerce_before_calculate_totals` at priority **10**. Responsibility:
compute the canonical unit price for every meal cart line and write it once
per pass via `WC_Product::set_price()`.

The unit price formula:

```
unit_base   = BundlePricer::calculate($total_qty, $bundles).applied
                ? result.effective_unit
                : wc_get_product($pid)->get_price('edit')
delta       = Selections::compute_price_delta($pid, $selection)
unit_price  = max(0.0, unit_base + delta)
```

**Idempotency invariant.** Every input is read from a source that does not
change as a result of `calculate_totals()` running:

* `wc_get_product($pid)->get_price('edit')` returns a freshly fetched
  `WC_Product` instance whose stored price is the catalog price, not the
  mutated `$item['data']` that lives on the cart item.
* `Selections::compute_price_delta()` reads the persisted `fn_selection`
  meta and queries `_fn_price_delta` on ingredient posts. None of these
  change inside a request.
* `BundlePricer::calculate()` is a pure function over the cart-totalled
  qty and the product's bundle config.

Therefore: running `apply()` N times in succession produces identical
`set_price()` arguments → identical totals.

Per-line bundle metadata is written to `$cart->cart_contents[$key]['fn_bundle']`
in the same shape the rest of the plugin already expects, so
`TotalsDisplay::compute_summary()` continues to render the customer-facing
"You saved (bundle)" row without changes.

### 3. `Surcharge` (`src/Cart/Surcharge.php`) — basket-level flat fee

Hooks `woocommerce_cart_calculate_fees`. If `Surcharge::status().applies`
(enabled, subtotal positive, subtotal < threshold), adds the configured flat
fee via `$cart->add_fee()`. WC clears the fees list at the start of each
calculation, so `add_fee` is idempotent.

Configurable in **Meal Prep → Settings → Surcharge** via four options:
`fn_surcharge_enabled`, `fn_surcharge_threshold`, `fn_surcharge_amount`,
`fn_surcharge_label`. No behaviour change from previous versions.

### 4. `StoreApiExtensions::gate_shipping_calc` + `filter_package_rates` — the shipping fix

Two filters together produce deterministic shipping behaviour:

**`gate_shipping_calc`** hooks `woocommerce_cart_ready_to_calc_shipping` at
priority **100**. Returns `false` (skip shipping calculation entirely) until
the customer has committed a fulfilment slot — i.e. until
`WC()->session->get('fn_fulfilment')` is populated. This is what stops the
basket page (and step 1 of checkout) from including a phantom default
delivery rate in the total before the customer has picked anything. The
existing `TotalsDisplay::maybe_hide_cart_shipping` couldn't do this for
Blocks because it gates on `is_cart()`, which doesn't fire for Store API
REST requests.

**`filter_package_rates`** hooks `woocommerce_package_rates` at priority
**100** (after third-party shipping plugins have added their rates). Reads
`WC()->session->get('fn_fulfilment')` and removes rates that don't match
the chosen type:

* `type === 'collection'`: keep only pickup-like rates (method id
  `local_pickup` / `pickup_location`, or label contains
  `collection`/`pickup`/`pick up`/`pick-up`).
* `type === 'delivery'`: drop all pickup-like rates.
* No fulfilment yet (cart page, before slot picker): pass through.

Because the filter removes the Delivery rate from the package when type is
collection, **there is no code path by which a delivery cost reaches the
total when the customer picked Collection**. The shop's WC config (one
zone with two `flat_rate`s — Delivery £fee and Collection £0) is what
backs this — both rates exist in the same package, and the filter narrows
to the one the customer wanted.

`pick_shipping_rate_id()` is now a one-liner: pick the first rate that
survives the filter, or null. The previous fragile "fall back to cheapest
rate" logic is gone — by the time `pick_shipping_rate_id()` runs there is
only one valid rate per type per package.

### 5. `TotalsDisplay` (`src/Cart/TotalsDisplay.php`) — display only

Reads `fn_selection` for add-on totals and `fn_bundle` cart_contents meta
for bundle savings. Exposes these as Store API cart_data fields
(`addon_total`, `bundle_savings`, `upsells`, `surcharge`) and renders them
as customer-visible summary rows in the classic shortcode cart/checkout.
**These are display-only — they do not add money to the cart.** Their
values are derived from the line item totals that BundlePricer has already
written.

### 6. `OrderItemMeta` (`src/Cart/OrderItemMeta.php`) — post-checkout persistence

Copies the `fn_selection` array onto the order line item meta as
`_fn_selection` (plus human-readable rows for the admin order screen) when
the order is created. No effect on totals.

## Hook table

| Hook                                              | Priority | Owner                                     |
|---------------------------------------------------|----------|-------------------------------------------|
| `woocommerce_add_cart_item_data`                  | 10       | `Selections::attach_selection`            |
| `woocommerce_add_to_cart_validation`              | 10       | `Selections::validate`                    |
| `woocommerce_get_item_data`                       | 10       | `Selections::display_selection`           |
| `woocommerce_before_calculate_totals`             | **10**   | `BundlePricer::apply`                     |
| `woocommerce_cart_calculate_fees`                 | 10       | `Surcharge::maybe_add_fee`                |
| `woocommerce_cart_ready_to_calc_shipping`         | **100**  | `StoreApiExtensions::gate_shipping_calc`  |
| `woocommerce_package_rates`                       | **100**  | `StoreApiExtensions::filter_package_rates`|
| `woocommerce_get_item_data`                       | 20       | `BundlePricer::render_notice`             |
| `woocommerce_checkout_create_order_line_item`     | 10       | `OrderItemMeta::persist`                  |
| `woocommerce_store_api_checkout_update_order_from_request` | 10 | `StoreApiExtensions::apply_to_order` |

## What changed in v1.8.0

* **`AddOnPricer` deleted.** Its responsibility (add the add-on price to
  the line) is folded into `BundlePricer::apply()`. The old class had a
  non-idempotent bug: `$item['data']->set_price( $item['data']->get_price() + $delta )`
  read the **mutated** cart-item price as the base, so each successive
  `calculate_totals()` call within a request added the delta again
  (`£4 → £5 → £6 → £7`). The merged pricer reads the catalog base from
  a fresh `wc_get_product()` instance and is idempotent by construction.
* **`BundlePricer::apply()` hook priority is now 10** (was 20). With
  AddOnPricer gone there is no longer a competing priority-10 pricer.
* **`StoreApiExtensions::filter_package_rates()` is new.** Replaces the
  brittle label-and-cost-based shipping rate matcher with a deterministic
  per-type filter. The old `pick_shipping_rate_id()` had a "fall back to
  cheapest rate" branch that could pick a delivery rate when the customer
  wanted Collection if the matching logic failed.
* **`StoreApiExtensions::pick_shipping_rate_id()` simplified.** Now picks
  the first rate that survives `filter_package_rates`. The cheap-fallback,
  label-preference, and most-expensive branches are gone.
* **Version bumped to 1.8.0** (behaviour-changing release).

## What changed in v1.8.5

* **Bundle totals are now penny-exact across split lines.** `BundlePricer::apply()`
  used to price every unit in a group at `effective_unit = total / qty`. WooCommerce
  rounds each cart line's `unit_price × qty` to the store's currency precision
  *independently*, so the sum of those rounded line totals drifted off the bundle
  total whenever the meals were spread over more than one line — e.g. 15 meals at
  £50 split across three 5-meal lines priced each unit at £3.3333…, which WC rounded
  to £16.67 per line and summed to **£50.01**. The same maths produced **£49.95** for
  fifteen separate single-meal lines. `apply()` now apportions the bundle total across
  the lines in integer pence and hands the rounding remainder to the final line, so the
  per-line totals always sum to exactly the bundle total. Add-on deltas are still added
  per unit on top and are unaffected.
* **Shipping rate cache is invalidated when the fulfilment changes.** WC caches a
  package's shipping rates in the session (`shipping_for_package_*`), keyed by a hash of
  the cart contents + destination + the `shipping` transient version — but **not** our
  `fn_fulfilment` value. So picking a slot (or switching delivery ↔ collection) without
  touching the cart left the hash unchanged, and `calculate_shipping()` could return the
  *empty* rate list cached before a slot was chosen (see `filter_package_rates`,
  v1.8.2) — skipping our filter entirely and charging **no delivery**.
  `StoreApiExtensions::flush_shipping_cache()` now bumps the `shipping` transient version
  on every fulfilment change (in `update_callback` and `clear_session_fulfilment`), so the
  next `calculate_shipping()` recomputes the rates and the delivery fee reaches the total.
* **Order summary no longer overlaps the action bar.** WC renders the order-summary
  sidebar `position: sticky`; inside the multi-step grid it detached on scroll and slid
  down over the Back/Next bar. The desktop stylesheet now pins the summary
  `position: static` and gives the action bar `z-index: 2`.
* **Version bumped to 1.8.5.**

## What changed in v1.8.6

* **The "Next" button validates the contact + address step.** The multi-step UI
  let customers click Next without filling required fields. `view.js` now runs
  `validateAddressStep()` before advancing past step 1 (and before any nav jump
  that skips it): it checks every visible, enabled required field
  (`required` / `aria-required="true"`) in the contact/shipping/billing
  containers via `checkValidity()` + non-empty value, **and enforces the phone
  number explicitly** regardless of WC's optional setting. On failure it nudges
  WC Blocks to show its inline errors and focuses the first offending field.
* **The order-summary shipping line now appears once a slot is chosen** rather
  than only on the payment step. The slot picker sets `data-fn-fulfilled="1"` on
  the checkout root when a slot is selected (and clears it when the method or
  postcode changes), and the CSS gate moved from `:not([data-fn-step="payment"])`
  to `:not([data-fn-fulfilled="1"])`. While the customer is still choosing
  delivery vs collection the line shows nothing; the moment they pick, it shows
  the chosen method and its cost on whatever step they're on.
* **Switching method/postcode clears the prior slot pick.** Previously the picker
  kept a stale selection (and could re-send it for the new type); it now resets,
  which also keeps the summary delivery line accurate.
* **Version bumped to 1.8.6.**

# Test cart scenarios

These are the carts used to verify the v1.8.0 pricing fix. Run each in a
private browsing session against a clean WC install with the plugin
activated and Surcharge configured at the default (threshold £23, amount £8,
label "Basket surcharge"). Verify the **Order Total** at each named step.

| # | Cart                                                       | Postcode    | Fulfilment              | Line price                  | Expected total                                  | Verifies                                                          |
|---|------------------------------------------------------------|-------------|-------------------------|-----------------------------|-------------------------------------------------|-------------------------------------------------------------------|
| 1 | 1× £4 meal, no add-ons                                     | any UK      | Delivery                | £4.00                       | £4 + £8 surcharge + £6 delivery = **£18**       | Baseline path; no add-on, no bundle.                              |
| 2 | 1× £4 meal + £1 add-on                                     | any UK      | Delivery                | £5.00                       | £5 + £8 + £6 = **£19**                          | **Bug 3 fixed.** Was reading £6 (or higher) per line.             |
| 3 | 1× £4 meal + £1 add-on                                     | any UK      | Collection              | £5.00                       | £5 + £8 + **£0** = **£13**                      | **Bug 2 fixed.** No delivery fee on Collection.                   |
| 4 | 1× £4 meal + £1 add-on, navigate steps 1→2→3→2→3           | any UK      | Delivery                | £5.00 unchanged at every step | **£19** unchanged                              | **Bug 1 fixed.** Total no longer drifts between checkout steps.   |
| 5 | 5× £4 meals + £1 add-on each, bundle tier "5 for £18"     | any UK      | Delivery                | £3.60 + £1.00 = £4.60/unit  | £23 + **£0** (≥threshold) + £6 = **£29**        | Bundle + add-on combine cleanly; surcharge correctly drops.       |
| 6 | 7× £5 meals (bundle "5 for £20", extras at per-meal rate) | any UK      | Delivery                | bundle £20 + 2×£4 = £28, effective unit £4.00 | £28 + £0 + £6 = **£34** | Bundle with extras above tier threshold.                          |
| 7 | 2× £4 meals (one with £1 add-on, one without)              | any UK      | Delivery                | £5 + £4 = £9 line subtotal  | £9 + £8 + £6 = **£23**                          | Per-line selection isolation; add-on only on the chosen line.     |
| 8 | 1× £4 meal, change slot 3× in step 2 (Collection → Delivery → Collection) | any UK | Final = Collection | £4.00 unchanged             | £4 + £8 + **£0** (final) = **£12**              | Shipping toggles deterministically; no stale add-on bleed.        |
| 9 | Empty cart                                                 | n/a         | n/a                     | n/a                         | £0 (no crash)                                   | Defensive: pricer skips items without `Selections::CART_KEY`.     |

### Regression smoke before pushing

Reproduce **scenario 2** against `main` (commit `a9a5348`, v1.7.35) first
— confirm the customer-reported behaviour (line price showing £6 or
higher). Then switch to the `checkout-pricing-rewrite` branch and confirm
line price reads £5.

### Things not verified by these scenarios

* Tax-inclusive store configurations. The pipeline uses `get_price('edit')`
  which returns the ex-tax stored value; WC's tax engine handles the
  rest downstream. Behaviour matches the previous code, but a manual
  check on a tax-on store is worth doing before shipping.
* Third-party pricing plugins (Subscriptions, dynamic pricing, role-based
  pricing) that also hook `woocommerce_before_calculate_totals` on meal
  products. The unified pricer is deliberately the single mutation owner
  for meal lines; composition with other pricers is not supported.

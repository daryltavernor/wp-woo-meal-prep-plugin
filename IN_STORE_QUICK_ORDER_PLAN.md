# In-Store Quick Order System — Implementation Plan

> **Status:** Implemented on `claude/serene-sagan-6WTVU` (v1.10.0). Awaiting WordPress/WooCommerce integration testing before merge to `main`.
> **Branch:** `claude/serene-sagan-6WTVU` (feature branch; merge to `main` when approved).
>
> **Access-model change (superseding the kiosk design below):** the screen is now a **WordPress admin page** under *Meal Prep → Quick Order*, gated to **Shop Managers + Administrators** (`manage_woocommerce`), with orders attributed to the signed-in user. The public page, store-password unlock, signed kiosk token and per-order PIN described in §2/§3 were removed; `KioskAuth` and `StaffPins` no longer exist, and the REST endpoints now use a capability + cookie-nonce check (`/instore/unlock` was dropped). Everything else (OrderFactory, shared pricing, fulfilment, pricing parity) is unchanged.
> **Prime objective:** fast, touch-first order entry on an iPad with the **fewest possible clicks and steps**, creating real WooCommerce orders identical to online orders for meal composition, fulfilment metadata and reporting.

This is an **extension** of the existing Fast Nutrition Meal Prep plugin. It must not change any existing behaviour. Every addition is additive registration plus two pure, behaviour-preserving extractions of existing logic so the offline tool and the online checkout call the *same* code.

---

## 1. How the existing plugin works (verified before planning)

### 1.1 Selection → order line items
There is **no** standalone "create order from selection" function today. Orders are produced entirely by the normal **cart → Blocks checkout** flow:

| Stage | Class / hook | Responsibility |
|---|---|---|
| Add to cart | `Cart\Selections::attach_selection` (`woocommerce_add_cart_item_data`) | Reads `$_REQUEST['fn_selection']`, normalises/validates, stores `fn_selection` on the cart item. |
| Price the line | `Cart\BundlePricer::apply` (`woocommerce_before_calculate_totals`) | **Cart-only.** `set_price()` per line = `catalog_base + selection_delta`, with bundle-tier apportionment in integer pence. Operates on `WC_Cart`. |
| Persist to order | `Cart\OrderItemMeta::persist` (`woocommerce_checkout_create_order_line_item`) | Copies `_fn_selection`, `_fn_macros_snapshot` and human-readable meta (Protein / Carb / Greens / Set Meal / Sweet / Add-ons / Macros) onto each order line item. |
| Fulfilment | `Checkout\StoreApiExtensions::apply_to_order` (`woocommerce_store_api_checkout_update_order_from_request`) | Writes `_fn_fulfilment` order meta from the WC session. |

Meals are **real WooCommerce simple products** with the Meal Builder enabled (a Standard product, a Bulk product, a Sweets product). Proteins / carbs / greens / set meals / sweets are **not** separate products or fees — they are `Ingredient` CPT entries referenced inside the `fn_selection` array; they only move the **price delta** (`_fn_price_delta`) and macros. One cart line = one meal product priced `base + delta`, optionally re-priced by bundle tier.

Reusable maths already exists as static methods: `Selections::compute_price_delta()` and `BundlePricer::calculate()`. What is **not** reusable yet: the application loop (grouping + pence apportionment + `set_price()`) inside `BundlePricer::apply(WC_Cart)`, and the meta writing inside the checkout-only `OrderItemMeta::persist()`.

### 1.2 Fulfilment metadata
Single order meta key `_fn_fulfilment`:
```php
[ 'type' => 'delivery'|'collection', 'profile_id' => int, 'date' => 'Y-m-d', 'slot' => [ 'start' => 'HH:MM', 'end' => 'HH:MM' ] ]
```
Read everywhere via `Checkout\FulfilmentDisplay::summary()`. Slots come from `Delivery\SlotAvailability::options($postcode, $method)`, exposed at `GET /fastnutrition/v1/slots`.

### 1.3 Standard vs bulk / set meals / sweets
- **Tier**: per-product meta `_fn_meal_tier` (`standard`|`bulk`), mirrored on ingredients via `_fn_tier`.
- **Set meals & sweets**: `Ingredient` CPT entries distinguished by `IngredientType` taxonomy slug (`set_meal`, `sweet`, plus `protein`/`carb`/`greens`). Products opt in via `_fn_allow_set_meal_mode` / `_fn_allow_sweet_mode` and per-product allow-lists `_fn_allowed_*_ids`.

**Risk note:** the prep dashboard, prep cache (`woocommerce_new_order` already fires on `wc_create_order`), labels and reporting key off `_fn_selection` + `_fn_fulfilment`. As long as the new tool writes identical meta, they pick up offline orders with **zero changes**.

---

## 2. Confirmed decisions

| Topic | Decision |
|---|---|
| Front-end surface | **Public front-end page** (shortcode/template the iPad bookmarks). |
| Authentication | **Public page + store-password unlock**, held by a long-lived signed token (no WordPress user session → never logged out by WP cookie/nonce expiry). Per-order **PIN** for staff attribution. Endpoint refuses any request without a valid token. Rate-limited. Lost device → rotate password/secret to invalidate all tokens. |
| Pricing scope | **Delivery fee only.** Delivery orders get the postcode's WC zone shipping line; collection adds nothing; the basket surcharge is **not** applied. |
| Payment methods | **Cash · Card terminal · Bank transfer · Account / on credit.** |
| Status mapping | **Paid → Completed, Unpaid → On hold.** Both reduce stock via WC's normal hooks. ("Account / on credit" pairs with Unpaid.) |
| Quantity | **Quantity stepper on every line type** (meal / set meal / sweet). Default 1. One line, qty N (counts toward bundle tiers). |
| Add-ons | Optional add-on available in **all** modes (protein build, set meal, bulk, sweet). |
| Bulk flow | Identical to standard (set-meal-or-protein → 1 carb + 1 green / 2 greens → optional add-on); different products/portions/pricing. |
| In-store offers | Quick Order settings allow an **optional per-set override allow-list** for proteins/carbs/greens/set-meals/sweets. Blank = inherit the live product; filled = show only those. |

---

## 3. Architecture

### 3.1 Two safe extractions (single source of truth — no behaviour change)
1. **`Cart\OrderItemMeta::write_line_meta( WC_Order_Item_Product $item, int $product_id, array $selection ): void`**
   Move the body of `persist()` here; `persist()` becomes a one-line caller. The offline builder calls the same method → identical line-item meal-composition + macros meta.
2. **`Cart\MealPricing::price_lines( array $lines ): array` (new)**
   Lift the grouping + bundle pence-apportionment loop out of `BundlePricer::apply()` into a pure function over `[ ['product_id','quantity','selection'], … ]` returning per-line unit prices (reusing `Selections::compute_price_delta()` + `BundlePricer::calculate()`). `BundlePricer::apply()` calls it for the cart; `OrderFactory` calls it for the order. Same pricing invariant for both paths (see `CHECKOUT_PRICING.md`).

### 3.2 New module `src/InStore/`
```
src/InStore/
  QuickOrderPage.php     # [fn_quick_order] shortcode/template + enqueues JS bundle + bootstrap config
  QuickOrderRest.php     # token+PIN-protected REST routes (unlock, config, submit)
  KioskAuth.php          # store-password verify, signed-token issue/validate, rate limiting
  StaffPins.php          # PIN <-> staff name/id map (hashed); resolve()
  OrderFactory.php       # THE reusable create-order-from-selection (internal WC PHP)
  InStoreSettings.php    # settings: store password, PINs, product-set mapping, override allow-lists,
                         #   payment methods, status mapping, email toggle
```
Registered with two lines in `Plugin::boot()`; a "Quick Order" submenu added in `Admin\MenuRegistry` for the settings screen.

### 3.3 `OrderFactory::create( array $payload ): WC_Order`
1. `wc_create_order( [ 'created_via' => 'fn_instore' ] )`.
2. Per line: re-validate via `Selections::normalize()` (never trust the client); `$order->add_product( wc_get_product($pid), $qty )`; override item subtotal/total from `MealPricing::price_lines()`; call `OrderItemMeta::write_line_meta()`.
3. `set_address()` billing (+ shipping for delivery); phone + email on billing.
4. `_fn_fulfilment` written in the exact shape above; `profile_id` resolved from the chosen slot.
5. **Delivery fee only:** for delivery, add a shipping line from `StoreApiExtensions::fees_for_postcode()`. Collection / surcharge: nothing.
6. `set_payment_method()` + title from the chosen method.
7. Tags: `_fn_offline_order = yes`, `_fn_staff_name`, `_fn_staff_id`, `created_via = fn_instore`.
8. Status: **paid → `completed`, unpaid → `on-hold`** (stock reduced by WC hooks).
9. `calculate_totals()` → `save()`.
10. Email toggle: only when an email was supplied **and** the toggle is on, fire the standard WC transactional email; otherwise set status silently.

### 3.4 Endpoints (custom namespace, **not** the public Store API)
- `POST /fastnutrition/v1/instore/unlock` — body `{ password }`. On success issues the signed kiosk token (httpOnly cookie). Rate-limited.
- `GET  /fastnutrition/v1/instore/config` — the 3 product-set IDs + meal configs (with override allow-lists applied), payment methods, status options. One round-trip to hydrate the screen.
- `POST /fastnutrition/v1/instore/order` — `{ lines[], customer, fulfilment, payment, status, send_email, pin }`.

`permission_callback` validates the signed token on every route (and the submit route additionally resolves the PIN → staff, rejecting on no match). Existing read endpoints `/ingredients`, `/meal-config`, `/slots` are reused as-is for populating pills.

---

## 4. Front-end (`assets/src/quick-order/`)
New `@wordpress/scripts` entry (the build globs `assets/src/*` → `assets/build/*`, so existing bundles are untouched). React, matching the existing stack.

- **`StickyBasketBar`** (persistent, all steps): item count; tap to expand/collapse to edit qty / remove lines; context-aware primary button (`Next` → `Submit`).
- **Step 1 `BuildFood`** (repeatable): set chooser (Standard / Bulk / Sweets pills) → `MealForm` (Option-1 pills = protein **or** set meal; conditional sides = 1 carb + 1 green / 2 greens; one optional add-on; **qty stepper**) or `SweetForm` (sweet + **qty**). "Add to basket" pushes the line and **resets instantly** for the next item.
- **Step 2 `Details`**: numeric-keypad phone (required); optional email; Delivery/Collection toggle → address + slot (delivery) or slot only (collection), reusing `/slots`; payment-method pills; paid/unpaid pills.
- **Step 3 `Review`**: basket summary; PIN pad; Submit → `POST /instore/order` → success screen with the new order number + "Start next order".

Speed/layout: dense **pills/chips**; horizontal scroll within an overflowing group; vertical scroll only as a last resort; instant add-and-reset; sensible defaults to minimise taps.

---

## 5. Data flow
```
iPad state (lines[])  ──POST /instore/order (signed token + PIN)──►  QuickOrderRest
                                                                       │ validate token, resolve PIN→staff
                                                                       ▼
                                                                  OrderFactory::create()
   Selections::normalize() ─ MealPricing::price_lines() ─ OrderItemMeta::write_line_meta()
   _fn_fulfilment ─ delivery shipping line ─ offline/staff tags ─ calculate_totals() ─ save()
                                                                       ▼
                                  WC › Orders + prep cache / labels / reporting (automatic)
```

---

## 6. Build order (when implementation starts)
1. Extractions: `OrderItemMeta::write_line_meta()` + `Cart\MealPricing` (with a PHPUnit test asserting cart prices are unchanged).
2. `OrderFactory` + PHPUnit coverage (line pricing parity, fulfilment meta, tags, status/stock, delivery fee).
3. `KioskAuth` + `StaffPins` + `InStoreSettings` + `QuickOrderRest`.
4. `QuickOrderPage` + `assets/src/quick-order/` React app.
5. Docs: update `README.md` + note the delivery-fee-only divergence in `CHECKOUT_PRICING.md`.

---

## 7. Remaining lower-risk items to settle during build
- Exact store-password change UX + token lifetime/rotation control in settings.
- Which capability (if any) gates the settings screen — assume `manage_woocommerce`, matching the rest of the plugin.
- Whether "Account / on credit" should always force Unpaid (assumed yes).
- Default product-set detection (auto-pick the existing Standard/Bulk/Sweets meal products; overridable in settings).

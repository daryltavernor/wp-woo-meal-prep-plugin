# Redis Object Cache — Configuration for this Plugin + WooCommerce

This store runs the [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache)
plugin in front of WooCommerce and **Fast Nutrition — Meal Prep**. This document
explains exactly which cache groups to exclude so that Redis accelerates the site
**without ever affecting the checkout or serving stale data**.

> **TL;DR** — Drop the [recommended config](#recommended-wp-configphp) into
> `wp-config.php`, set Redis to `maxmemory-policy noeviction`, and flush the
> object cache. You do **not** need to exclude any of this plugin's own caches.

---

## How group exclusion works

The Redis Object Cache drop-in reads a single constant in `wp-config.php`
(defined **before** `wp-settings.php` is required):

```php
define( 'WP_REDIS_IGNORED_GROUPS', [ 'group-a', 'group-b' ] );
```

Any cache group listed there is served from PHP runtime memory only and is never
written to Redis — i.e. it falls back to its non-persistent / database behaviour.
The drop-in ships with **no** ignored groups by default; the list is entirely
opt-in. (See the plugin
[FAQ → "How can I ignore a cache group?"](https://github.com/rhubarbgroup/redis-cache/blob/develop/FAQ.md#how-can-i-ignore-a-cache-group).)

Related constants (also merged in the drop-in):

- `WP_REDIS_UNFLUSHABLE_GROUPS` — groups kept across a cache flush.
- `WP_REDIS_GLOBAL_GROUPS` — groups shared across all sites on multisite.

---

## Does this plugin need any groups excluded?

**No.** An audit of `src/` confirms the plugin makes **no direct `wp_cache_*`
calls and registers no custom object-cache groups.** All of its caching uses two
mechanisms that are safe with a persistent object cache by design:

| Where | Cache | Group | Invalidation |
|-------|-------|-------|--------------|
| `src/Delivery/SlotAvailability.php` | `fn_slot_bookings_{version}` — booked delivery-slot counts | `transient` | `WC_Cache_Helper` version bump on every order create/status change (+1 h TTL backstop) |
| `src/Checkout/StoreApiExtensions.php` | `shipping` transient version | `transient` | Bumped when a customer picks a slot or switches delivery ↔ collection, forcing WC to recompute package rates |
| `src/PostTypes/IngredientCatalog.php` | `fn_ingredient_catalog` — ingredient map | `transient` | `delete_transient()` on edit (+1 day TTL) |
| `src/Admin/SettingsPage.php`, `src/InStore/InStoreSettings.php` | admin notices | `transient` | 30 s TTL |

Versioned transients are the WordPress-canonical, object-cache-safe invalidation
pattern. These entries **should stay in Redis** — excluding them would only remove
the performance benefit, not improve correctness.

---

## The group that matters for checkout: `wc_session_id`

The plugin stores the customer's chosen fulfilment slot in the **WooCommerce
session** and drives shipping calculation off it (`StoreApiExtensions::flush_shipping_cache()`).
WooCommerce sessions live in the object-cache group **`wc_session_id`**, while the
authoritative copy is the `wp_woocommerce_sessions` database table.

- **Eviction is safe.** If a session key is evicted from Redis, WC falls back to
  the DB and recomputes — totals stay correct.
- **A stale Redis copy is the risk.** If the Redis copy diverges from the DB, a
  customer can see the wrong shipping cost / total at checkout. Excluding
  `wc_session_id` makes the DB the single source of truth and removes that window
  entirely.

This is why `wc_session_id` is the one WooCommerce group worth excluding for a
store that, like this one, computes shipping from a session value.

---

## Recommended `wp-config.php`

```php
define( 'WP_REDIS_IGNORED_GROUPS', [
    // WooCommerce session/cart — the wp_woocommerce_sessions table is
    // authoritative; excluding this keeps the chosen fulfilment slot and
    // shipping totals from ever being read stale at checkout.
    'wc_session_id',

    // High-churn, low-value WordPress groups: cheap to recompute and they
    // only pollute Redis with frequently-changing entries.
    'counts',   // wp_count_posts() / wp_count_comments()
    'plugins',  // active-plugin lookups
    'themes',   // theme lookups
] );
```

### Equally important: Redis eviction policy

Group exclusion is only half the story. Configure the Redis server with:

```
maxmemory-policy noeviction
```

(or `volatile-lru`, which only evicts keys carrying a TTL). The default
`allkeys-lru` can silently drop keys under memory pressure; with `noeviction`,
writes fail loudly instead of corrupting cache state. Combined with excluding
`wc_session_id`, this guarantees the cart and checkout never lose state because
of Redis.

Optionally cap stray long-lived keys:

```php
define( 'WP_REDIS_MAXTTL', 86400 * 7 ); // 7 days
```

---

## Optional: enforce it from PHP instead of `wp-config.php`

If you prefer not to edit `wp-config.php`, the same effect for sessions can be
achieved from a mu-plugin (works with **any** object cache backend, not just
Redis), by marking the group non-persistent early:

```php
add_action( 'plugins_loaded', static function (): void {
    if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
        wp_cache_add_non_persistent_groups( [ 'wc_session_id' ] );
    }
}, 0 );
```

This is intentionally **not** shipped inside the plugin: marking WooCommerce
sessions non-persistent is a site-wide decision that should stay in the site
owner's hands (it can conflict with deliberate Redis-session setups). Prefer the
`wp-config.php` constant unless you have a reason not to.

---

## Verifying

After applying the config:

1. **Settings → Redis** in wp-admin — confirm Status is *Connected* and the
   "Ignored groups" list shows your entries.
2. `wp redis status` (WP-CLI) — confirms the drop-in is valid and the connection
   is live.
3. Place a test order through delivery and collection, switching slots mid-flow,
   and confirm the shipping total updates correctly each time.

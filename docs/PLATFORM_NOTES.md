# Platform notes

The connector ships for Zen Cart today; this page captures the
platform-specific gotchas worth flagging up front for integrators on
each storefront platform we plan to support. The integration contract
itself — four swap-points, filter registry, surface-tagged events — is
identical on every platform; only the wiring location moves.

## Zen Cart 1.5.7 (supported since connector v1.3.19)

**Compatibility**

- Manifest declares `v157` alongside `v158` and `v200`.
- PHP **7.4+** (PHP 8.x recommended; tested on 8.3).
- Admin **Tools → Connect to Seekmodo** self-heals on 1.5.7 via
  `zen_register_admin_page()` (singular); releases before v1.3.19
  only called the 1.5.8+ plural API.

**Install path**

Use **Plugin Manager → Upload New Plugin → Install/Upgrade** (see
[`INSTALL.md`](INSTALL.md) §2 and §2a). Do **not** rely on copying
only the `zc_plugins/Seekmodo/` tree — catalog-root shims must land
in your catalog directory (e.g. `/shop/`).

**Upgrade path**

Prefer **Plugin Manager → Update** over **Tools → Seekmodo Updates →
Apply update** when moving between releases on 1.5.7. The in-plugin
auto-updater replaces the versioned plugin directory but may not
re-copy catalog-root `numinix_seekmodo_*.php` shims on older cores.

**Subdirectory catalogs**

Stores whose catalog root is `/shop/` (or any `DIR_WS_CATALOG` other
than `/`) work without extra Seekmodo configuration. Pairing builds
the callback URL from `HTTPS_CATALOG_SERVER` + `DIR_WS_CATALOG`.
Register the **apex domain** (e.g. `example.com`) in Seekmodo admin,
not the `/shop/` path.

## Zen Cart 1.5.8+ (current)

**Swap-point locations**

| Surface | File (typical) | Helper to insert |
|---|---|---|
| Search   | `includes/classes/class.search.php` near the top of the storefront's existing Typesense helper | `numinix_seekmodo_run_search($params)` |
| Indexer  | `includes/functions/typesense_indexer_lib.php` near the top of the bulk-upsert function | `numinix_seekmodo_run_bulk_upsert($docs, $collection)` |
| Click    | `ajax/ajax_search_log.php` after the existing local INSERT | `numinix_seekmodo_mirror_click($kw, $pid, $pos, $bot, $opts)` |
| Typeahead| `ajax/ajax_typeahead.php` at the top | `numinix_seekmodo_run_typeahead($q, $max)` |

**Boot order**

Zen Cart loads `autoLoadConfig[80]` after `configure.php` but before
any storefront request handler runs. The connector registers itself
there, so any of the above swap-points can `function_exists()` against
the helper without further glue.

The notable exception is `transfer_products.php` (CLI). CLI invocations
DO `require_once 'includes/application_top.php'`, which DOES run the
autoloader, so the indexer path works without any special handling.

**Filter registry init**

Drop a file into `includes/auto_loaders/config.<theme>_seekmodo_filters.php`
that registers itself at `autoLoadConfig[90]` (after the connector's
80-stage boot), with a sibling `init_includes/init_<theme>_seekmodo_filters.php`
that calls `numinix_seekmodo_register_filter_mapping()` for each
storefront filter param. The connector's own init runs first, so the
mapping registry is guaranteed to exist by the time your init runs.

**Theme-specific filter conventions**

Most Zen Cart themes that ship attribute filters use one of:

- `nmn_filters` sidebox — emits underscore-separated `options_values_id`
  lists keyed by lower-cased `products_options_name` (Redline, several
  Numinix builds).
- Vanilla Zen Cart "Music Genre / Record Company" filters — single-value
  string match keyed by product attribute name.
- Custom theme builds — anything goes.

The connector's default `coerce='auto'` handles the first two without
configuration. Custom themes that use comma-separated values, multi-key
filter params (`?brand[]=12&brand[]=34`), or non-integer attribute IDs
should register an explicit `coerce` per-mapping.

PHP array-style `?brand[]=12&brand[]=34` is the only common shape NOT
supported by the auto-coercion — the connector parses single string
values. For array-style params, flatten in your init include before
the registry call:

```php
if (isset($_GET['brand']) && is_array($_GET['brand'])) {
    $_GET['brand'] = implode('_', array_map('intval', $_GET['brand']));
}
```

**Bot-check + nonces**

Zen Cart's session id is available via `session_id()` after
`application_top.php`. The connector's `_numinix_seekmodo_session_id()`
helper picks it up automatically. If your storefront has its own
shopper-token system (Redline ships `numinix_search_log_session_token()`),
the connector prefers that — same token across search and click
beacons keeps the bot-check classifier consistent across the same
shopper visit.

## WooCommerce (planned)

**Swap-point locations**

Filter into `pre_get_posts` for the search query, and `wp_ajax_*` for
the typeahead and click endpoints. The connector helpers will be
exposed via a WP plugin file that mirrors the Zen Cart layout:

```
seekmodo-connector/
├── seekmodo-connector.php          # plugin header + init
├── includes/
│   ├── class-seekmodo-search.php   # wraps numinix_seekmodo_run_search
│   ├── class-seekmodo-typeahead.php
│   └── class-seekmodo-events.php
└── docs/                            # same docs, WP-flavored
```

**WooCommerce-specific filter conventions**

WP's filter URL convention uses bracketed multi-values:
`?filter_brand=12,34&filter_color=red`. Pre-flatten in the WP plugin's
init hook before calling `register_filter_mapping`, same approach as
Zen Cart's array-style flattening above.

WC variations (parent + child SKUs) require an additional indexer
decision: index parent products only (drops variation-specific filters
like color/size) vs. index every variation (filter precision at the
cost of result-set bloat). The Seekmodo gateway accepts either; the
storefront filter registry has to match whatever the indexer chose.

## Shopify (planned)

**Swap-point locations**

Shopify's storefront search is server-rendered by Liquid templates,
which makes the "drop a PHP swap-point in the search class" pattern a
non-starter. The Shopify connector will instead expose a Storefront
GraphQL middleware:

- A Cloudflare Worker (or App Proxy endpoint) that intercepts the
  `predictiveSearch`/`search` queries and routes through the Seekmodo
  gateway when the tenant is enrolled.
- An App Block (Online Store 2.0) that renders the type-ahead dropdown
  + click beacon client-side.

The integration contract stays the same (filter registry, surface
tags, mirror helpers) but the language changes — TypeScript on the
Worker side, Liquid + JS in the App Block.

**Shopify-specific notes**

- Shopify's metafield system maps cleanly to indexed Typesense fields
  — define a metafield per shopper-facing filter and the connector
  registry maps URL params directly.
- Customer Accounts API session IDs are short-lived; the typeahead
  helper falls back to anonymous bucketing via the
  `_shopify_customer_id` cookie hash, which the Seekmodo gateway
  treats as a session id with `prefix='shopify_anon_'`.

## Generic / custom platforms

The connector is portable to any PHP application that can:

1. Load PHP files (the connector tree is self-contained).
2. Set the seven `NUMINIX_SEEKMODO_*` constants from a config source.
3. Provide a session id and IP via standard `$_SERVER` / `session_id()`
   semantics.

The four swap-points are abstract — whatever your platform calls its
"search executor", "indexer loop", "click log endpoint", and
"autocomplete endpoint" are the right targets.

Non-PHP platforms (Node, Python, Go) talk directly to
`mcp.seekmodo.com` over HTTP — see the gateway's own README for the
HMAC signing convention. The connector code in this directory is the
canonical reference implementation for the signing + circuit-breaker
pattern.

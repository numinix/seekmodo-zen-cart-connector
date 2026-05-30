# Wiring sidebar / attribute filters

This is the page to read when a storefront already has filter checkboxes
in its theme — brand, type, color, size, capacity, whatever — and you
want them to apply correctly to **Seekmodo-routed** search results
(both the full-page SERP and the type-ahead dropdown).

The short version: **register a mapping for each filter param, once, at
boot.** The connector takes it from there.

## Why a registry?

Storefronts ship with arbitrary sidebar plugins. Each plugin invents its
own URL parameter naming convention, and each tenant's indexer maps
those into Typesense fields under whatever names made sense at index
time. Three common situations:

- The URL param matches the indexed field exactly (`?brand=12` →
  `brand:=[12]`). Easy.
- The URL param is the storefront-side name but the indexed field was
  renamed (Redline: `?type=…` → `p_type:=[…]`, because the original
  `type` collided with a Typesense reserved word).
- The URL param has no indexed counterpart at all (custom per-shopper
  filters, real-time inventory feeds, B2B price-list gates). Needs the
  local-intersection escape hatch — see "Filters not in the index"
  below.

A registry decouples all three. The storefront declares the mapping it
needs in one place; the connector builds the right Typesense
`filter_by` clause on every request automatically.

## The 90% case: indexed attribute filters

Drop a short PHP file into the storefront's catalog init includes (or
your theme bootstrap, anywhere that runs once per request before
`class.search.php` executes). Example for a Zen Cart tenant whose
indexer matches the field names exactly:

```php
// includes/init_includes/init_seekmodo_filters.php

if (function_exists('numinix_seekmodo_register_filter_mapping')) {
    // Each call: URL parameter name, Typesense field name.
    numinix_seekmodo_register_filter_mapping('brand', 'brand');
    numinix_seekmodo_register_filter_mapping('color', 'color');
    numinix_seekmodo_register_filter_mapping('size',  'size');
}
```

That's it. The connector now builds the `filter_by` clause for you on
every call:

| Shopper URL | filter_by sent to gateway |
|---|---|
| `?keyword=trailer&brand=12` | `brand:=[12]` |
| `?keyword=trailer&brand=12_34_56` | `brand:=[12,34,56]` |
| `?keyword=trailer&brand=12&color=red` | `brand:=[12] && color:=\`red\`` |

Multi-value selectors are auto-detected. The default separator is `_`
(matches the Zen Cart `nmn_filters` sidebox); pass `multi_sep` to
register a different one (`,` is also accepted out of the box without
configuration).

### Default mappings

Out of the box the connector ships these three mappings:

| URL param         | Typesense field | Coerce    |
|-------------------|-----------------|-----------|
| `brand`           | `brand`         | `int_list` |
| `type`            | `p_type`        | `int_list` |
| `capacity_by_lbs` | `capacity`      | `int_list` |

They exist so a vanilla Redline-style tenant works without any custom
init include. Re-registering the same URL param replaces the default —
so a tenant whose indexer kept the original `type` field name can
simply call:

```php
numinix_seekmodo_register_filter_mapping('type', 'type');
```

…and the new mapping overrides the default.

## The 10% case: filters not in the index

Some storefront filters can't be pushed to the gateway:

- Per-shopper state (wishlists, B2B price lists, "in stock at MY store")
- Filters added by 3rd-party plugins whose values aren't part of the
  indexer query
- Live feeds that change every few minutes (real-time inventory,
  flash-sale flags)

For these, compute the allow-list in PHP and intersect with the
gateway's result:

```php
$result = numinix_seekmodo_run_search($params);

if ($result !== null && $myCustomFilter->active()) {
    $allowIds = $myCustomFilter->getMatchingProductIds(); // your function
    $result   = numinix_seekmodo_apply_local_filter($result, $allowIds);
}

if ($result !== null) {
    return $result;
}
// else fall through to native LIKE path as always
```

`apply_local_filter()` keeps the gateway's rank order (relevance,
LTR rerank, A/B variant, etc.) intact — it just drops products that
aren't in your allow-list and rewrites `total`. If the custom filter
matches nothing it returns an empty `products` array with `total=0`;
the caller doesn't have to special-case that.

You can stack both approaches: registered mappings handle the indexed
filters, `apply_local_filter()` handles the rest.

## Coercion types

When you register a mapping you can pass `coerce` to control how the
raw `$_GET` value becomes a filter literal:

| Coerce        | Input                | Output                              |
|---------------|----------------------|-------------------------------------|
| `int_list`    | `12_34_56`           | `field:=[12,34,56]`                 |
| `string_list` | `red_blue_green`     | `field:=[`red`,`blue`,`green`]`     |
| `int`         | `42`                 | `field:=42`                         |
| `string`      | `acme-corp`          | `field:=`acme-corp``                |
| `bool`        | `true` / `1` / `yes` | `field:=true`                       |
| `range`       | `100..500` or `_from`/`_to` pair | `field:>=100 && field:<=500` |
| `auto`        | (default)            | `int_list` when every token is numeric, else `string_list` |

For range filters specifically, the connector accepts EITHER the
`min..max` shorthand OR the Zen Cart–style paired params:

```
?weight=100..500
?weight_from=100&weight_to=500
```

Both produce `weight:>=100 && weight:<=500`.

## Type-ahead filters

The same registry powers in-context type-ahead. When a shopper is
browsing a category page and starts typing in the search box, the
suggestions automatically scope to the visible filter set — no extra
work required.

If a particular type-ahead surface should NOT inherit `$_GET` filters
(e.g. a header search box that's supposed to span the whole store),
clear them before calling the helper:

```php
$saved = $_GET;
$_GET  = ['keyword' => $term];
$result = numinix_seekmodo_run_typeahead($term, 8);
$_GET   = $saved;
```

…or pass a precomputed `filter_by` opt that overrides the registry for
this one call.

## Troubleshooting

**Filter is in the URL but didn't apply.**

1. Check the connector debug log — set `NUMINIX_SEEKMODO_DEBUG=true`
   in Admin → Configuration → Seekmodo Search and tail
   `logs/numinix_seekmodo.log`. Every gateway request logs its
   `filter_by` clause; missing values usually mean the URL param name
   didn't match a registered mapping.
2. Confirm the Typesense field name matches what the indexer is
   actually writing. The connector's filter clause hits Typesense
   verbatim — a typo in `register_filter_mapping`'s second argument
   means the gateway returns "field not found" and the result set
   collapses to zero. Check the indexer's document shape
   (`transfer_products.php` style scripts always log the field set on
   `--dry-run`).
3. If the storefront has its own post-Typesense SQL that re-filters
   the result set (Redline's `class.search.php` did, pre-fix), make
   sure that SQL preserves the filter clauses too — pushing filters
   gateway-side doesn't help if the storefront undoes it locally
   afterward.

**Filter applied gateway-side AND storefront-side, returning empty.**

You're double-filtering. Pick one. Either:

- Drop the storefront's local filter SQL and trust the gateway, or
- Skip the registry mapping for that param and rely on the local SQL.

The local-intersection helper (`apply_local_filter`) is for the
"different filter, not the same one twice" case.

**Type-ahead works but the SERP filter doesn't, or vice versa.**

Confirm that BOTH entry points (`numinix_seekmodo_run_search` and
`numinix_seekmodo_run_typeahead`) are reached. The two paths share the
filter registry but live in different request handlers; if your
type-ahead AJAX endpoint doesn't load the storefront's init includes,
the registry is empty when the helper runs. Make sure your AJAX entry
point boots `application_top.php` (or whatever the platform-equivalent
init flow is); the connector's autoloader fires from there.

## Reference

PHP API:

| Function | Returns | Purpose |
|---|---|---|
| `numinix_seekmodo_register_filter_mapping($urlParam, $field, $opts = [])` | `void` | Declare a filter mapping. |
| `numinix_seekmodo_filter_mappings()` | `array` | Read-only snapshot of the current registry. |
| `numinix_seekmodo_reset_filter_mappings()` | `void` | Drop every registration (test hook). |
| `numinix_seekmodo_build_filter_by()` | `?string` | Compose the Typesense `filter_by` from `$_GET`. |
| `numinix_seekmodo_apply_local_filter($remote, $localIds)` | `array` | Intersect gateway result with local allow-list. |

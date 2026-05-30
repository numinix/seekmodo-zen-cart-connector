# Wiring autocomplete / type-ahead

Type-ahead (the AJAX dropdown that appears under the search box while a
shopper is typing) is a separate surface from the full-page SERP. It
has different latency and relevance requirements, and it generates its
own click signal that must be attributed correctly. The connector
exposes a dedicated helper for it; this page is the integration guide.

## Why a separate helper?

`numinix_seekmodo_run_search()` is tuned for the full SERP — it pages
through up to 12,500 IDs so Zen Cart's local pagination has the full
result list. That's overkill for a dropdown that shows 8 rows and never
paginates. `numinix_seekmodo_run_typeahead()`:

- Caps `per_page` at 15 and never pages.
- Uses prefix + infix tuning (the gateway's commerce vertical default
  already wires this in `SearchDefaults`; the helper just trims field
  lists down).
- Records a typeahead **impression event** tagged
  `extra.surface='typeahead'` so analytics + LTR can keep autocomplete
  signal separate from SERP signal.
- Returns lean rows with `products_id`, `value` (name), `model`,
  `price`, `url`, optional `image`.

## Server-side wiring (Zen Cart example)

Most Zen Cart stores already have something like `ajax/ajax_typeahead.php`
that talks directly to Typesense. To route it through the gateway:

```php
<?php
// ajax/ajax_typeahead.php — minimal Seekmodo-aware version.

require __DIR__ . '/../includes/configure.php';
ini_set('include_path', DIR_FS_CATALOG . PATH_SEPARATOR . ini_get('include_path'));
chdir(DIR_FS_CATALOG);
require_once 'includes/application_top.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=15');

$q   = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
$max = max(1, min(15, (int)($_GET['max'] ?? 8)));

if ($q === '' || mb_strlen($q) < 2 || mb_strlen($q) > 80) {
    echo json_encode(['q' => $q, 'items' => []]);
    exit;
}

// 1. Connector path (preferred).
if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()
    && function_exists('numinix_seekmodo_run_typeahead')) {
    $result = numinix_seekmodo_run_typeahead($q, $max);
    if ($result !== null) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    // null => off / shadow / circuit-open / failure — fall through.
}

// 2. Native fallback. (Whatever the storefront did before — direct
//    Typesense call, native LIKE, whatever.) Keep the existing code
//    intact below this comment.

// ... existing direct-Typesense / LIKE path ...
```

The connector helper takes care of:

- Gateway routing with prefix/infix-tuned defaults.
- Shopper-context attribution (`session_id`, `ua`, `ip`, `referer`) so
  the gateway's bot-check classifier runs and downstream telemetry
  doesn't land with `is_bot=NULL`.
- Mode-aware short-circuiting: off → null, shadow → null + record
  impression for verifier, enforce → live results.
- Recording the **impression event** with surface tag.

Failure modes degrade to native — the storefront keeps working.

## Client-side wiring: the click beacon

Type-ahead **clicks** are the bit most integrations miss. When a shopper
clicks a suggestion the browser navigates directly to the product page,
bypassing whatever click-beacon you have on your SERP template. Without
a dedicated beacon, every type-ahead-driven product visit is invisible
to your CTR analytics and LTR training data.

The pattern: fire a `navigator.sendBeacon` to your existing click-log
endpoint right before the navigation, tagging the event as
`surface=typeahead`. Zen Cart example (jQuery UI autocomplete):

```js
// In the autocomplete `select` handler, before navigating away.
select: function (event, ui) {
  if (!ui.item || !ui.item.url || !ui.item.products_id) return;

  try {
    var fd = new FormData();
    fd.append('keyword',     jQuery('#quick_find_header_input').val() || '');
    fd.append('products_id', ui.item.products_id);
    fd.append('position',    ui.item.position || 0);
    fd.append('surface',     'typeahead');
    navigator.sendBeacon('ajax/ajax_search_log.php', fd);
  } catch (e) { /* fire-and-forget */ }

  window.location.href = ui.item.url;
  return false;
},
```

The `position` field is the 1-based index of the suggestion in the
dropdown. The server-side mapping (next section) reads `surface`
verbatim and passes it to the connector.

On the server, your existing click-log endpoint accepts the surface tag
and forwards it to the connector:

```php
// ajax/ajax_search_log.php (the bits that change are the $surface line
// and the $opts argument to mirror_click).

$surface = trim((string)($_POST['surface'] ?? 'results'));
$allowed = ['results', 'typeahead'];
if (!in_array($surface, $allowed, true)) {
    $surface = 'results';
}

// ... your existing bot-check / local-row write ...

if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()
    && function_exists('numinix_seekmodo_mirror_click')) {
    numinix_seekmodo_mirror_click(
        $keyword,
        $productsId,
        $position,
        $botReason,
        ['surface' => $surface]
    );
}
```

If you'd rather not branch on the surface server-side, call the
dedicated wrapper:

```php
numinix_seekmodo_mirror_typeahead_click($keyword, $productsId, $position, $botReason);
```

Identical behavior, just less verbose at the call site.

## Why `position` matters

LTR training data needs the rank of the click relative to the
impression. For SERP clicks that's the row index in the listing. For
type-ahead clicks the rank is the row index in the dropdown. Both go
into `numinix_telemetry_search_events.position`, and the LTR feature
extractor reads `surface` from `extra_json` so it can keep two
separate clickthrough models (one for SERP, one for typeahead).

If `position` is missing or zero the row still records, but it won't
contribute to LTR training — the trainer drops rows with no rank
signal.

## Recommended return-payload shape

The connector returns:

```json
{
  "q": "trail",
  "items": [
    {
      "products_id": 693,
      "value": "Redline Trailer 6000lb",
      "model": "RL-T6000",
      "price": "$2,499.00",
      "url": "https://store.example.com/index.php?main_page=product_info&products_id=693",
      "image": "<img src=\"...\" />"
    }
  ],
  "total": 24
}
```

`items` is a sparse-ok array. Fields:

- `products_id` (int)  — always present
- `value` (string)     — product name; safe to show as the row label
- `model` (string)     — SKU/model; useful for B2B catalogs
- `price` (string)     — formatted by `zen_get_products_display_price`
- `url` (string)       — canonical product page link
- `image` (string)     — small `<img>` HTML produced by
                         `zen_get_products_image(60,60)`; omit when no
                         image is configured

Storefronts can ignore any field they don't need. The Zen Cart
default theme renders `image + value + price`; B2B themes can swap to
`model + value`.

## Cache hints

The gateway response includes Typesense cache headers (`Cache-Control:
private, max-age=...`). The connector doesn't override them, so a
typeahead AJAX endpoint that wants a per-keystroke cache can set its
own `Cache-Control: public, max-age=15` at the top of the endpoint —
the bot-check classifier still runs because the connector reads the
session id from the cookie header, not from the cache key.

## Troubleshooting

**Type-ahead dropdown shows results but no row gets written to
`numinix_search_log`.**

Type-ahead only records an **impression** (visible suggestions); it
does NOT write a local row in the storefront's search log table. That's
intentional — type-ahead keystrokes happen 5-10× per real search and
would balloon the search-log table without proportional analytics
value. If you want a local row per keystroke, call your existing search
log helper inside your `ajax/ajax_typeahead.php` after the connector
helper returns (your call — costs you table size, gains you a local
audit trail).

**Type-ahead clicks aren't appearing in the gateway's events stream.**

Three usual suspects:

1. The autocomplete JS isn't firing `navigator.sendBeacon` before the
   `window.location.href` navigation. Some Safari versions drop pending
   network calls on hard navigation; `sendBeacon` is specifically
   designed to survive that, but a custom autocomplete library that
   uses `XMLHttpRequest` instead will lose the request.
2. The click-log endpoint isn't passing `surface` through to
   `numinix_seekmodo_mirror_click()`. The mirror function defaults to
   `surface=results`, so the event lands but in the wrong bucket.
3. Bot-check is flagging the click. Check the
   `numinix_telemetry_search_events.bot_reason` column — `nonce_missing`
   is the common one for type-ahead because the SERP-template-issued
   HMAC nonce isn't present on direct-from-typeahead navigations.
   That's expected behavior; bot-check sees fewer signals on a
   type-ahead click and tolerates that by not failing it open.

## Reference

PHP API:

| Function | Returns | Purpose |
|---|---|---|
| `numinix_seekmodo_run_typeahead($q, $max, $opts)` | `?array` | Execute a typeahead lookup. |
| `numinix_seekmodo_mirror_typeahead_click(...)` | `void` | Mirror a typeahead-surface click event. |
| `numinix_seekmodo_mirror_click(..., ['surface' => ...])` | `void` | Generic click mirror with custom surface tag. |

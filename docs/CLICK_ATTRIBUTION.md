# Click attribution and the event model

The connector mirrors three event types to the gateway. This page
explains what each one is, when to fire it, and how to tag it so the
analytics + LTR pipeline can keep your data sets clean.

## The three event types

| Event       | When to fire                                                                | Required fields                          | Optional fields                  |
|-------------|------------------------------------------------------------------------------|------------------------------------------|----------------------------------|
| `search`    | A shopper executed a search and a result page was rendered.                 | `keyword`, `session_id`                  | `result_count`, `extra.variant`  |
| `impression`| A set of products was *visible* to the shopper (SERP render, type-ahead).   | `keyword`, `session_id`, `products_ids[]` | `extra.surface`, `extra.shadow`  |
| `click`     | A shopper clicked a product link that originated from a search context.     | `keyword`, `session_id`, `products_id`, `position` | `bot_reason`, `extra.surface` |

Search and impression are the "context" events; click is the "outcome"
event. The LTR trainer joins them on `(tenant_id, session_id, keyword,
products_id)` to derive click-through rate and rank-position signals.

## What "surface" means

Surface is the most useful piece of metadata you can attach. It tells
the gateway which UI affordance a shopper interacted with so analytics
can answer questions like:

- "Are type-ahead clicks converting at the same rate as SERP clicks?"
- "Did the redesigned filter sidebar move clicks deeper down the page?"
- "Which surface produces the highest LTR training signal for this
  keyword bucket?"

Convention:

| Surface     | Meaning                                                                 |
|-------------|-------------------------------------------------------------------------|
| `results`   | Full-page SERP click (Zen Cart `index.php?main_page=search_result`).    |
| `typeahead` | AJAX autocomplete dropdown click.                                       |
| `related`   | "Related products" sidebar on a product detail page.                    |
| `category`  | Category browse / facet drilldown (no keyword search involved).         |

Custom surfaces are fine — pick a short snake-case label and stay
consistent across deploys. The gateway stores the value verbatim under
`numinix_telemetry_search_events.extra_json.surface`; the trainer
buckets by exact match.

## Wire shapes

### Click

```php
numinix_seekmodo_mirror_click(
    $keyword,          // string, the search keyword
    $productsId,       // int, the clicked product
    $position,         // int, 1-based rank
    $botReason,        // ?string, bot-check verdict (or null)
    [
        'surface' => 'results',
        'extra' => [
            'variant' => 'lexical',     // optional A/B test bucket
            'page'    => 1,             // optional pagination depth
        ],
    ]
);
```

The fourth-argument `$botReason` is the storefront's own classifier
verdict. The gateway runs its own classifier independently — both
verdicts end up in the event row so we can audit-trail any discrepancy.

### Impression

```php
numinix_seekmodo_mirror_impression(
    $keyword,
    $productIds,       // array<int> in rank order, capped at 100 server-side
    [
        'surface' => 'results',
        'extra' => ['page' => 1],
    ]
);
```

Impressions are optional for the SERP but **strongly recommended for
type-ahead** — the connector's `run_typeahead` helper fires the
impression itself, so most storefronts never call this directly.

### Search

The search event is implicit in `numinix_seekmodo_run_search()` — the
connector writes the search row via the storefront's local
`numinix_search_log_record_search()` helper, and the gateway derives
the search side of the event from the `/v1/search` request itself.
Storefront integrators don't fire `search` events manually.

## Bot-check verdicts

The `bot_reason` field on a click event mirrors whatever your local
bot-check pipeline decided. Common values:

| Bot reason      | Meaning                                                                  |
|-----------------|--------------------------------------------------------------------------|
| `null`          | Not a bot.                                                               |
| `nonce_missing` | The SERP-rendered HMAC nonce wasn't on the beacon (typeahead, replay).   |
| `nonce_invalid` | Nonce present but failed verification.                                   |
| `fast_click`    | Click recorded < 0.5s after the search row was written.                  |
| `velocity_clicks` | Click rate per session exceeded the cap.                               |
| `<ua-class>`    | Local UA classifier verdict (`bot:googlebot`, `bot:semrush`, etc.).      |

Bot-flagged clicks still record — the local row AND the gateway event
get written with `is_bot=true`. The LTR trainer drops them at training
time; analytics tiles segment them as "bots blocked".

## Local vs gateway: belt-and-suspenders

The connector NEVER replaces the storefront's local click-log table.
The order in `ajax/ajax_search_log.php` is:

1. Bot checks (local).
2. Write `numinix_search_log` row (local).
3. Mirror click event to gateway (best-effort).

If the gateway is down the local row is the audit trail. If the local
DB is down the gateway is the eventual-consistency backstop. Both can
break at once and the storefront still serves shoppers (the beacon is
fire-and-forget).

Implications for analytics:

- **Use local rows** for the "tamper-resistant" view per tenant — every
  click is captured even if the gateway was down.
- **Use gateway events** for cross-tenant aggregate views, LTR
  training, and the Seekmodo admin dashboards. Slightly looser but
  centrally accessible.

Periodic reconciliation (`tools/verify_<tenant>_seekmodo.py`)
counts rows on both sides and alerts on drift > 1%.

## Cross-platform notes

The same three event types apply on every platform. The connector you
ship for WooCommerce, Shopify, or a custom Laravel storefront should:

1. Expose a `mirror_click($kw, $pid, $pos, $bot, $opts)`-shaped helper.
2. Accept `surface` and `extra` keys verbatim, forward to the gateway.
3. Default surface to `'results'` to preserve v1.0.2 / older-connector
   call sites.

The gateway's `EventsTool` already accepts the canonical envelope shape
plus the legacy single-event shim; storefront connectors can ship
either. New connectors should target the canonical shape (see the
gateway's `EventsTool::inputSchema`) but the legacy shim is
permanently supported.

## Troubleshooting

**Clicks are recording locally but not landing in the gateway.**

Tail `logs/numinix_seekmodo.log` while clicking. With
`NUMINIX_SEEKMODO_DEBUG=true` every mirror call logs its outcome —
non-2xx, timeout, breaker-open events all show up there. If the log is
silent the storefront isn't reaching the mirror helper (check that
`function_exists('numinix_seekmodo_mirror_click')` is true at the
beacon-handler entry point — boot-order corner cases can leave it
undefined).

**Clicks recording in the wrong surface bucket.**

The single most common cause is a forgotten `surface` parameter on the
JS beacon — `navigator.sendBeacon('ajax/ajax_search_log.php', fd)` with
`fd` missing the `surface` field defaults the server-side mirror to
`results`, so type-ahead clicks land in the SERP bucket. Verify in
DevTools that the form data POST includes `surface=typeahead`.

**`position` is always zero.**

The JS beacon needs to compute the 1-based rank of the clicked
suggestion. For SERPs this is usually a server-side render-time
`data-position` attribute on the product tile; for typeahead it's the
1-based index in the dropdown array. A `position=0` makes the row
ineligible for LTR training (which drops zero-rank rows), so analytics
counts will look fine but training data will be sparse.

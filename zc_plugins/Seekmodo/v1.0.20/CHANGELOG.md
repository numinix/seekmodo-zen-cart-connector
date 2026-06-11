# Seekmodo Zen Cart connector — v1.0.20 changelog

## Summary

v1.0.20 ports the **client-side LRU cache + race-prevention guard**
that landed in `seekmodo-wordpress-connector` v0.5.0 onto the Zen
Cart typeahead. JS-only; the storefront-side suggest endpoint
(`numinix_seekmodo_suggest.php`), the `Client::suggest()` server hop,
the gateway tools — all unchanged. This is **phase B** of the
connector typeahead spec at
`seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md`.

The user-visible win: backspacing `boats → boat` and re-typing back
to `boats` now renders both intermediate states from the in-memory
LRU instead of round-tripping the suggest endpoint twice. On a hot
prefix the second render is sub-millisecond; on the first network
miss the latency floor is unchanged.

The reliability win: a fast typist outrunning the network can't
overwrite a freshly-rendered `boats` dropdown with a slow `boa`
response any more. Each in-flight fetch carries a `lastQ` guard
that drops the result if the user has moved on.

## What changed

### MODIFIED: `catalog/includes/templates/template_default/jscript/jscript_seekmodo_typeahead.js`

* **Added LRU cache** (32 entries, `Map`-backed). Keyed on
  `MAX_PRODUCTS|normalized_q` where
  `normalized_q = q.toLowerCase().replace(/\s+/g, ' ').trim()`.
  Map's insertion-order iteration gives LRU semantics for free —
  `get()` re-inserts on hit (most-recent wins), `keys().next().value`
  is always the oldest entry.

  Cache values are the parsed `{ok, q, keywords, products,
  categories, total}` payload, so a hit skips both the network AND
  re-rendering. Default size 32 covers 3-5 typical session prefixes
  with their backspaced variants without bloating the long-lived JS
  heap.

* **Added `lastQ` guard.** The previous version aborted in-flight
  XHRs but did NOT discard already-arrived responses for stale
  queries. A fast typist's `boa → boats` could land a slow `boa`
  response after the freshly-rendered `boats` dropdown, replacing it
  with the wrong rows. The new code stamps `lastQ = q` at debounce
  fire time and the `.then()` handler bails when `q !== lastQ`.

* **Aligned cache-key normalization with the gateway.** The
  gateway's `TypeaheadTool::cacheKey` uses
  `strtolower + collapse-whitespace + sha1` as the APCu key
  derivation. The Zen Cart client's `cacheKey()` mirrors that
  derivation (minus the sha1 — small numeric strings are fine for
  Map lookup) so client + server cache scopes align. A query that
  the gateway already serves from APCu also hits the client LRU
  next time around.

### Why phase C is not in this release

Phase C of the spec (browser-token gateway-direct fetch, skipping
the PHP hop entirely) needs the connector to mint a short-lived JWT
at page render and the gateway's CORS preflight to accept the token
on `/v1/typeahead`. The CORS allowlist landed in monorepo commit
`27425e2`, but the Zen Cart connector doesn't have a browser-token
mint helper today. Adding one is queued for v1.0.21 along with the
flat-rows-shape `/v1/typeahead` migration; this release stays
JS-only so it can ship behind a single template-file swap on every
existing tenant.

## Files touched

| File | Change |
|------|--------|
| `catalog/includes/templates/template_default/jscript/jscript_seekmodo_typeahead.js` | LRU cache + lastQ guard |
| `manifest.php` | bumped to `v1.0.20`, prepended changelog blurb |

No PHP, no schema, no gateway-call shape changes.

## Migration

* **Deployed via auto-update**: nothing to do. The in-plugin
  `UpdateClient` already supports `dev-ephemeral` -> `seekmodo-2026-06`
  rotated in v1.0.18, so v1.0.19 -> v1.0.20 flows on the standard
  signed-zip path.
* **Hand-deployed on a custom template**: copy the new
  `jscript_seekmodo_typeahead.js` into your template's `jscript/`
  folder. The cache + guard are in the JS, no PHP changes needed.

## Reference

* Spec: `seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md` (phase B)
* Reference implementation:
  `seekmodo-wordpress-connector/assets/typeahead/seekmodo-typeahead.js`
* Gateway-side cache:
  `seekmodo/services/mcp-gateway/src/Tools/TypeaheadTool.php`
  (APCu micro-cache + Cloudflare s-maxage=30)

# Troubleshooting

A flowchart-shaped reference for the most common issues. If your
problem isn't covered here, please file an issue at
<https://github.com/numinix/seekmodo-zen-cart-connector/issues> with
the `<docroot>/logs/numinix_seekmodo.log` excerpt and the output of
**Tools → Connect to Seekmodo** (which renders a diagnostic block).

---

## "I installed the plugin but storefront search behaves the same"

This is normal. The plugin defaults to `MODE=off`. Until you flip the
mode (from <https://admin.seekmodo.com> after pairing) the swap-points
are inert and you keep seeing native Zen Cart behaviour.

Quick check:

```sql
SELECT configuration_key, configuration_value
FROM   configuration
WHERE  configuration_key LIKE 'NUMINIX_SEEKMODO_%';
```

You should see 13 rows, with `NUMINIX_SEEKMODO_MODE = 'off'` until
you change it. If you see fewer than 13, the Scripted Installer
didn't finish — re-run the install from Plugin Manager.

## "I clicked Connect to Seekmodo and got an error"

The most common causes, in order of likelihood:

### Clock skew

The HMAC envelope rejects requests where the storefront's clock is
more than 5 minutes off from the gateway's. Run `date -u` on your
Zen Cart host and compare to `https://mcp.seekmodo.com/v1/health`'s
`server_time` field. Anything outside ~30 seconds is concerning;
anything outside 5 minutes will outright fail.

Fix: ensure NTP / chrony is configured and active.

### CORS / cookies

The pair flow opens `seekmodo.com/connect` in your existing browser
session. If you're behind a corporate VPN or proxy that strips the
SameSite=Lax session cookie on the redirect back, the round-trip can
fail silently with "expected callback never received".

Fix: try the pairing in a clean browser profile.

### Outbound connectivity

Your Zen Cart host must be able to reach `https://mcp.seekmodo.com`
on port 443. If your shared host blocks outbound HTTPS to addresses
not on an allow-list, ask your provider to add `mcp.seekmodo.com`.

Test from the host:

```bash
curl -fsS https://mcp.seekmodo.com/v1/health | head -c 200
```

Should print a JSON `"ok":true,...` snippet.

## "The pair worked but admin.seekmodo.com says the storefront is silent"

This means seekmodo.com isn't seeing telemetry — the storefront isn't
sending search/click events to the gateway. Causes:

### `MODE` is still `off`

Check `configuration.NUMINIX_SEEKMODO_MODE`. Until it's `active`,
`shadow`, or `enforce`, the swap-points are inert.

### Bot-check is filtering you out

Verify with `is_bot=0` filter on your test traffic. If your test
laptop is on a datacenter IP range or the UA looks bot-ish, the
gateway flags the events but the analytics dashboard hides them by
default.

### Local cache is stale

`<docroot>/cache/numinix_seekmodo_remote_config.cache` should be
refreshed every 5 minutes when `MODE != off`. If it's older than 15
minutes, RemoteConfig::pull() is failing silently. Set
`NUMINIX_SEEKMODO_DEBUG=true` in `configuration` and look for
`remote_config_pull` lines in `<docroot>/logs/numinix_seekmodo.log`.

## "Search is returning nothing where it used to return results"

Almost always the gateway's circuit breaker is open and the storefront
fell through to native Zen Cart `LIKE` — but it's `MODE=enforce` and
you removed the native fallback wiring during a custom theme port.

```bash
grep 'circuit_breaker' <docroot>/logs/numinix_seekmodo.log | tail
```

If you see `circuit_breaker_open`, the gateway is failing your store's
calls. Causes:

- DNS / IPv6 flake (the connector forces IPv4 since v1.0.1 — make
  sure you're on v1.0.1+).
- Gateway 5xx rate spike (check <https://status.seekmodo.com>).
- Tenant rate limit exceeded (see admin.seekmodo.com → Billing).

Set `MODE=shadow` while you investigate — that returns native results
to shoppers immediately while still letting you compare gateway
behaviour.

## "Plugin Manager shows v1.0.3 but I uploaded v1.0.4"

Zen Cart stages the new version dir alongside the old one but doesn't
auto-promote. Click the **Update** action on the Seekmodo row, then
reload the Plugin Manager page.

If the Update action is missing, you've hit the Plugin Manager's
"two installed versions" condition: uninstall v1.0.3 first, then
install v1.0.4.

## "The indexer cron isn't running"

The plugin doesn't ship a cron entry by default — your Zen Cart cron
job (whatever invokes `transfer_products.php`) drives indexing. The
plugin's swap-point inside `numinix_typesense_indexer_bulk_upsert()`
just intercepts what would have been a direct Typesense call.

If you've moved to v1.0.5+ with managed-mode cron rendering, the
operator-side `tools/install_redline_connector.py` writes
`/etc/cron.d/numinix-seekmodo-<tenant>` based on
`NUMINIX_SEEKMODO_INDEXER_SCHEDULE`. Check that file exists and the
`crond` daemon is running.

## "I see `_text_match:desc, in_stock:desc, _eval(...):asc` errors"

You're hitting the Typesense 0.25 three-sort-clause cap on a query
where the gateway's reranker tried to add a fourth comparator. This
was fixed in the gateway with a workaround for Typesense 0.25; if you
still see it, you're either on a stale gateway version (check
`https://mcp.seekmodo.com/v1/health` and report it) or you've
configured a `sort_by` override that exceeds the cap directly. Drop
one clause from `NUMINIX_TYPESENSE_KEYWORD_SORT_BY` /
`NUMINIX_TYPESENSE_BROWSE_SORT_BY` and the error clears.

## Logs to gather when filing an issue

1. `<docroot>/logs/numinix_seekmodo.log` — last ~200 lines.
2. The `Tools → Connect to Seekmodo` page's diagnostic block (it has
   a copy-to-clipboard button).
3. Output of `php -v` and `php -m | grep -E 'apcu|curl|openssl'`.
4. Your plugin version (Plugin Manager).
5. Whether the issue reproduces with `MODE=shadow` (which proves the
   gateway is reachable but is misbehaving in `enforce`).

Email these to `support@seekmodo.com` or paste them into a GitHub
issue. Don't include the value of `NUMINIX_SEEKMODO_SHARED_SECRET`.

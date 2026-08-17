# Configuring the Seekmodo Zen Cart connector

The plugin operates from a small set of `NUMINIX_SEEKMODO_*` constants
held in Zen Cart's `configuration` table. This file documents every
constant — what it does, where it's authoritative, and when you'd want
to change it.

> **TL;DR.** Most constants are managed remotely from
> <https://admin.seekmodo.com> and pulled into your store automatically
> via `tenant.snapshot`. The local copy is the **fallback** when the
> gateway is unreachable. You rarely need to touch them by hand.

---

## Authority model

Each constant has an **authority**: the place that's expected to set
its canonical value. The plugin's `RemoteConfig::writeThrough()` reads
`tenant.snapshot` from the gateway every 5 minutes (cached in APCu)
and mirrors the gateway-authoritative subset into the local
`configuration` table. Anything with `authority=local` is set in your
Zen Cart admin and never overwritten by the snapshot pull.

Constants not yet in the writeThrough list (e.g. `NUMINIX_SEEKMODO_URL`
or `NUMINIX_SEEKMODO_TENANT_ID`) are local-only by design.

## Connection constants (always local)

| Constant | Default | Description |
|---|---|---|
| `NUMINIX_SEEKMODO_URL` | `https://mcp.seekmodo.com` | Gateway base URL. Override only if you've stood up a private gateway. |
| `NUMINIX_SEEKMODO_TENANT_ID` | _empty_ | Per-store ID. Auto-populated by the **Connect to Seekmodo** flow. Treat as semi-secret. |
| `NUMINIX_SEEKMODO_SHARED_SECRET` | _empty_ | 64-hex HMAC key paired with the tenant. Auto-populated by the pair flow. **Treat as a secret.** |

If you re-pair the storefront (e.g. after rotating the secret on
seekmodo.com), the **Connect to Seekmodo** page overwrites both
constants atomically.

## Mode constants (gateway-authoritative)

| Constant | Default | Authority | Description |
|---|---|---|---|
| `NUMINIX_SEEKMODO_MODE` | `off` | gateway | `off` \| `active` \| `shadow` \| `enforce`. Flip from <https://admin.seekmodo.com>. |
| `NUMINIX_SEEKMODO_DEFAULT_MODE` | `active` | gateway | The mode the connector falls through to when `MODE` is unset. Introduced in v1.0.5 — see [W6b in the project plan](https://github.com/numinix/seekmodo/blob/main/PROJECT_PLAN.md). |
| `NUMINIX_SEEKMODO_AUTO_PROMOTE` | `true` | gateway | When `true`, `active` mode auto-promotes from `shadow` to `enforce` after observing 50 healthy gateway responses. |

The four modes:

| Mode | Storefront behaviour | Telemetry behaviour |
|---|---|---|
| `off` | Direct Typesense (or `LIKE`). | Local-only. |
| `shadow` | Direct Typesense; gateway also called for diff. | Mirrored to both sinks. |
| `enforce` | Gateway primary; native fallback only on circuit-breaker open / gateway null. | Mirrored to both sinks. |
| `active` | State machine; promotes / demotes based on gateway health. | Mirrored to both sinks. |

## Performance / network constants

| Constant | Default | Authority | Description |
|---|---|---|---|
| `NUMINIX_SEEKMODO_TIMEOUT_MS` | `250` | gateway | Per-request hot-path budget. Requests over `2 *` this open the circuit breaker. |
| `NUMINIX_SEEKMODO_INDEX_BATCH` | `500` | gateway | Documents per `/v1/index` chunk during the indexer cron. |
| `NUMINIX_SEEKMODO_DEBUG` | `false` | gateway | When `true`, the connector writes verbose lines to `<docroot>/logs/numinix_seekmodo.log`. |

The default 250 ms timeout is intentionally aggressive — a slower
Seekmodo response wouldn't beat your storefront's native Typesense or
`LIKE` path anyway, and the circuit breaker would open. If you've
provisioned a private gateway with higher tail latency, raise this in
admin.seekmodo.com.

## Indexer schedule (gateway-authoritative, v1.0.5+)

| Constant | Default | Authority | Description |
|---|---|---|---|
| `NUMINIX_SEEKMODO_INDEXER_SCHEDULE` | `daily` | gateway | One of `hourly`, `every_4h`, `every_12h`, `daily`, `manual`. Drives the cron line emitted by `tools/install_redline_connector.py`. |

The connector ships a small cron renderer that translates this enum
into a `/etc/cron.d/numinix-seekmodo-<tenant>` entry on managed-mode
installs. On unmanaged hosts, you'd write this cron yourself; the
constant is still surfaced for diagnostic visibility.

## Suggest widget (local, v1.3.69+)

| Constant | Default | Authority | Description |
|---|---|---|---|
| `NUMINIX_SEEKMODO_SUGGEST_ENABLED` | `true` | local | Inject the storefront suggest widget. |
| `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` | `false` | local | `false` = subscribed split-rail `<seekmodo-suggest>` widget. `true` = v1.0.20 flat dropdown. Installer/upgrade resets leftover `true`. |

## Where to look when in doubt

1. **Admin → Configuration → Seekmodo Search** in your Zen Cart admin
   (visible only when the configuration group's `visible` column is
   `1`; toggle from the database if the group is hidden).
2. The gateway-side authoritative copy — sign in to
   <https://admin.seekmodo.com>, find your tenant, and check the
   **Settings** tab.
3. The local cache file at
   `<docroot>/cache/numinix_seekmodo_remote_config.cache` — last
   successful `tenant.snapshot` pull. If this is older than 15 minutes
   while `MODE != off`, the storefront will keep working but
   gateway-driven config changes have stopped propagating; see
   [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

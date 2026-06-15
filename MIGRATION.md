# Migrating from in-tree classes to the shared SDK

Starting with v1.1.0 (2026-06-14) the connector pulls its transport,
breaker, mode-FSM, pairing, and events code from a shared Composer
package, [`numinix/seekmodo-connector`](https://packagist.org/packages/numinix/seekmodo-connector)
(PSR-4 root `Numinix\SeekmodoSdk\`). The same package backs the WordPress
and AKS connectors so a single fix lands everywhere on the next release.

This document describes the **two-step** rollout we're taking to keep
the Zen Cart connector production-stable through the transition.

## Step 1 — v1.1.0 (you are here): vendor the SDK, leave existing classes in place

What ships in v1.1.0:

| Surface                                       | Status                                                                                |
|-----------------------------------------------|---------------------------------------------------------------------------------------|
| `Numinix\Seekmodo\Client`                     | **Unchanged.** Still the entry point used by procedural shims.                        |
| `Numinix\Seekmodo\Pairing`                    | **Unchanged.**                                                                        |
| `Numinix\Seekmodo\RemoteConfig`               | **Unchanged.**                                                                        |
| `Numinix\Seekmodo\AutoPromoter`               | **Unchanged.**                                                                        |
| `Numinix\Seekmodo\ApcuCircuitBreakerStore`    | **Unchanged.**                                                                        |
| `Numinix\Seekmodo\CircuitBreakerStore`        | **Unchanged.**                                                                        |
| `Numinix\Seekmodo\EnvProbe`                   | **Unchanged.** Platform-specific, stays here.                                         |
| `Numinix\Seekmodo\PromotionStore`             | **Unchanged.** Platform-specific, stays here.                                         |
| `Numinix\Seekmodo\UpdateApplier`              | **Unchanged.** Platform-specific (in-plugin updater), stays here.                     |
| `Numinix\Seekmodo\UpdateClient`               | **Unchanged.** Platform-specific.                                                     |
| `Numinix\Seekmodo\WellKnownWriter`            | **Unchanged.** Platform-specific.                                                     |
| `Numinix\SeekmodoSdk\*`                       | **NEW.** Vendored at build time from `numinix/seekmodo-connector ^0.2`.               |

Procedural shims (`numinix_seekmodo_client.php`,
`numinix_seekmodo_search_lib.php`, `numinix_seekmodo_indexer_lib.php`,
`numinix_seekmodo_events_lib.php`, etc.) keep calling the existing
`\Numinix\Seekmodo\*` classes — they do not have to change in v1.1.0.

What this buys us right now:

- The shared SDK is **available** under `\Numinix\SeekmodoSdk\*`. New
  code (the Lexmodo connector, future internal tools, a v1.2 admin
  surface) can use it immediately.
- Bug fixes that land in the SDK now propagate to WP + AKS + future
  connectors on their next release.
- The Zen Cart storefront's hot path is **unaffected** — none of the
  v1.0.22 entry points changed.

## Step 2 — v1.2.0 (planned): collapse legacy classes into thin adapters

Once v1.1.0 has soaked on Redline + a second Zen Cart tenant for one
full release cycle, v1.2.0 will:

1. Shrink each `\Numinix\Seekmodo\Client` / `Pairing` / `RemoteConfig` /
   `AutoPromoter` to a **thin adapter** that:
   - Reads ZC-flavoured config (`MODULE_NUMINIX_SEEKMODO_*`,
     `configuration.configuration_key`, the `seekmodo` cache row).
   - Constructs the corresponding `\Numinix\SeekmodoSdk\*` instance
     with that config + an `\Numinix\SeekmodoSdk\Storage\ApcuBreakerStore`
     + an `\Numinix\SeekmodoSdk\Storage\ApcuCache`.
   - Delegates every call to the SDK instance.
2. Delete the lifted internals from each adapter (HMAC building,
   breaker FSM, snapshot polling, JWT verify, etc. — anything the
   SDK now owns).
3. Add `tests/ZenCartAdapterTest.php` confirming each adapter still
   exposes the static factory shape (`Client::fromConfiguration()`,
   `RemoteConfig::writeThrough()`, `AutoPromoter::pushSnapshot()`)
   procedural shims rely on.

Net effect: `library/Numinix/Seekmodo/Client.php` drops from ~43KB to
~3-4KB; `Pairing.php` from ~26KB to ~2KB; `RemoteConfig.php` from
~18KB to ~2KB. Functional behaviour unchanged.

The procedural shims still don't need touching — they keep calling
`\Numinix\Seekmodo\Client::fromConfiguration()`; the call just resolves
to a 4KB adapter rather than 43KB of inline code.

## Step 3 — v2.0.0 (eventual): drop the BC layer

Optional. Once every shim has been audited and migrated to call
`\Numinix\SeekmodoSdk\*` directly (or once the procedural surface has
fully moved into a class-based admin / storefront integration), the
v1.x BC adapters can go away.

## How to vendor the SDK locally

```bash
# From the repo root:
composer install --no-dev
python tools/build_release.py
```

`build_release.py vendor_sdk()` runs `composer install --no-dev` and
then copies `vendor/numinix/seekmodo-connector/src/*` into
`zc_plugins/Seekmodo/v<X.Y.Z>/catalog/includes/library/Numinix/SeekmodoSdk/`.
The vendored files are part of the zip but `.gitignore`'d so they
never get committed (the SDK version is pinned by `composer.json`).

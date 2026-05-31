# Seekmodo for Zen Cart

[![Latest release](https://img.shields.io/badge/release-v1.0.5-2563eb)](https://github.com/numinix/seekmodo-zen-cart-connector/releases/latest)
[![Download (seekmodo.com)](https://img.shields.io/badge/download-seekmodo.com%2Fplugins%2Fzen--cart-22c55e)](https://seekmodo.com/plugins/zen-cart)
[![Docs](https://img.shields.io/badge/docs-INSTALL%20%C2%B7%20CONFIG%20%C2%B7%20UPGRADE-64748b)](docs/)

The official **Seekmodo** plugin for **Zen Cart 1.5.8+**. It routes the
storefront's product search, indexer cron, click beacon, and type-ahead
autocomplete through the Seekmodo platform at `mcp.seekmodo.com`, while
keeping Zen Cart's native search permanently armed as a graceful fallback.

> Looking for the SaaS product? Visit **<https://seekmodo.com>**.
> Looking for a different platform (WordPress / WooCommerce / Shopify /
> custom)? See `seekmodo.com/plugins`.

## Why Seekmodo

- **AI search out of the box.** Vector recall + commerce-aware reranking
  on top of Typesense — no model wrangling, no prompt engineering.
- **Zero ops.** Tune everything from <https://admin.seekmodo.com>;
  the plugin pulls config + secrets in real time. No keys to copy on
  upgrade, no cron to wire by hand.
- **Auto-pairing.** Click "Connect to Seekmodo" in the Zen Cart admin,
  approve on seekmodo.com, and the tenant ID + HMAC secret round-trip
  back into your store automatically.
- **Self-tuning.** Default `active` mode auto-promotes from shadow to
  enforce based on observed gateway health, and auto-demotes on
  sustained failures.
- **Always-on safety net.** If the gateway is unreachable, the storefront
  falls back to native Zen Cart `LIKE` search inside the request — your
  shoppers never see an empty page.

## Quick start

```bash
# Download the latest signed zip from seekmodo.com:
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.5.zip
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.5.zip.sha256
sha256sum -c seekmodo-zen-cart-v1.0.5.zip.sha256
```

Then in Zen Cart admin → **Plugin Manager** → **Upload New Plugin** →
select the zip → **Install**. The Scripted Installer creates the
`Seekmodo Search` configuration group and seeds 13 `NUMINIX_SEEKMODO_*`
rows with safe defaults (`MODE=off`, `AUTO_PROMOTE=true`, `TIMEOUT_MS=250`).

After install, click **Tools → Connect to Seekmodo** to round-trip your
tenant ID + HMAC secret from <https://seekmodo.com/connect>. Then flip
the mode to `active` from <https://admin.seekmodo.com>.

Full instructions: [`docs/INSTALL.md`](docs/INSTALL.md).

## Modes

| Mode | Behaviour |
|---|---|
| `off` | Bypass Seekmodo entirely. Direct Typesense + local row. |
| `active` | **Recommended.** Auto-promotes from shadow → enforce based on observed gateway health; auto-demotes on sustained failures. |
| `shadow` | Calls the gateway for observation/diff but always returns the native result to the shopper. |
| `enforce` | Use the gateway as primary; native `LIKE` only when the circuit breaker is open or the gateway returns null. |

## What the plugin swaps

The plugin attaches at four discrete swap-points in your Zen Cart
storefront. Each is an opt-in, mode-aware route — passing `MODE=off`
short-circuits all of them:

1. **Storefront search** — `class.search.php::numinix_elastic_search_results()`.
2. **Indexer cron** — `transfer_products.php`'s bulk-upsert loop, auto-chunking at 500 docs / request.
3. **Click beacon** — `ajax/ajax_search_log.php` mirrors clicks to the gateway in addition to the local row.
4. **Type-ahead** — `ajax/ajax_typeahead.php` routes autocomplete with prefix-tuned scoring and surface tags.

Detailed contract: [`docs/INTEGRATION.md`](docs/INTEGRATION.md). All
four paths degrade gracefully — gateway down or circuit breaker open
falls through to native Zen Cart search inside the same request.

## Documentation

- [`docs/INSTALL.md`](docs/INSTALL.md) — install + first-pair + verify.
- [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) — every `NUMINIX_SEEKMODO_*` constant, what it does, and where it's authoritative (local vs. admin.seekmodo.com).
- [`docs/UPGRADE.md`](docs/UPGRADE.md) — version-to-version notes, migration from `NuminixSeekmodo` (legacy unique_key), rollback recipe.
- [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) — common pitfalls, log sources, circuit breaker diagnostics.
- [`docs/INTEGRATION.md`](docs/INTEGRATION.md) — internals: the four swap-points, boot order, versioning rules.
- [`docs/FILTERS.md`](docs/FILTERS.md) — sidebar / attribute filter wiring via the runtime registry.
- [`docs/TYPEAHEAD.md`](docs/TYPEAHEAD.md) — autocomplete dropdown wiring + click-beacon pattern.
- [`docs/CLICK_ATTRIBUTION.md`](docs/CLICK_ATTRIBUTION.md) — event model, surface tags, bot-check verdicts.
- [`docs/PLATFORM_NOTES.md`](docs/PLATFORM_NOTES.md) — per-platform caveats (Zen Cart now, others planned).

## Configuration constants

The minimal set you must touch on first install. Most other constants
default sensibly and most are managed remotely by admin.seekmodo.com via
`tenant.snapshot`.

| Key | Default | Notes |
|---|---|---|
| `NUMINIX_SEEKMODO_URL` | `https://mcp.seekmodo.com` | Gateway base URL; rarely changed. |
| `NUMINIX_SEEKMODO_TENANT_ID` | _empty_ | Auto-populated by the **Connect to Seekmodo** flow. |
| `NUMINIX_SEEKMODO_SHARED_SECRET` | _empty_ | Auto-populated by the **Connect to Seekmodo** flow. |
| `NUMINIX_SEEKMODO_MODE` | `off` | Flip to `active` from admin.seekmodo.com once paired. |

Full list and semantics: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md).

## Releases

Each release publishes a signed zip at
`https://seekmodo.com/plugins/seekmodo-zen-cart-v<X.Y.Z>.zip` plus a
SHA-256 sidecar and an ed25519 detached signature. The `latest`
pointer is in `https://seekmodo.com/plugins/manifest.json` — the
in-plugin auto-updater (Sprint 4) consumes that endpoint.

The release pipeline is **operator-local**, not GitHub Actions. Per
the seekmodo monorepo's [`.cursor/rules/deploy.mdc`](https://github.com/numinix/seekmodo/blob/main/.cursor/rules/deploy.mdc)
and [`AGENTS.md`](https://github.com/numinix/seekmodo/blob/main/AGENTS.md)
§1, GHA is not used for routine deploys (it costs us billable
minutes). Running the release locally and letting the existing
seek-api01 deploy webhook handle the *deployment* of the resulting
PR keeps the routine release at zero GHA minutes.

### How to cut a release (maintainers)

```bash
# 1. Bump the version in zc_plugins/Seekmodo/v<NEW>/manifest.php
#    (or copy v<OLD> -> v<NEW> with `python tools/build_release.py --bump patch`).
# 2. Update CHANGELOG.md.
# 3. Commit + tag + push.
git add .
git commit -m "release: vX.Y.Z"
git tag vX.Y.Z
git push origin main vX.Y.Z

# 4. Build + sign + publish (operator-local).
python tools/build_release.py --auto-pr
```

`tools/build_release.py --auto-pr`:

1. Reads the version from the highest-numbered `v*/manifest.php`.
2. Builds the zip, writes `dist/seekmodo-zen-cart-v<X.Y.Z>.zip`,
   computes its SHA-256 (`<zip>.sha256`), and signs it with the
   operator-local ed25519 key at
   `RELEASE_SIGNING_KEY_PATH` / `/etc/numinix/release-signing.key`
   / `/etc/numinix/marketing-jwt.ed25519` (writes `<zip>.sig`).
3. Vendors the matching public key into the plugin tree at
   `zc_plugins/Seekmodo/v<X.Y.Z>/admin/release-signing.pub`.
4. Opens an auto-merging PR against
   [`numinix/seekmodo`](https://github.com/numinix/seekmodo)
   carrying the zip, signature, and manifest update under
   `services/marketing-site/public/plugins/`. When that PR merges
   to `main`, the seek-api01 deploy webhook redeploys
   marketing-site, publishing the new download. **No GHA minutes
   are consumed.**

### One-time pipeline setup

The cross-repo PR step needs a fine-grained GitHub PAT with `repo`
scope on `numinix/seekmodo`. Set it once via the seekmodo monorepo:

```bash
# From the seekmodo monorepo root:
python tools/setup_connector_release_pipeline.py
```

The PAT lands in your local environment as
`SEEKMODO_PUBLISH_TOKEN`. The script also writes the same value as a
GitHub Actions secret on this repo so the **emergency-fallback**
manual `workflow_dispatch` of `.github/workflows/release.yml` works
when an operator can't run the build locally (see the docstring at
the top of that workflow).

## Support

- Public docs: <https://seekmodo.com/docs>
- Issues: <https://github.com/numinix/seekmodo-zen-cart-connector/issues>
- Email: `support@seekmodo.com`
- Security disclosures: `security@seekmodo.com` (also <https://seekmodo.com/security>)

## License

This plugin is published by **Numinix Technology Inc.** under the GPL
v2 license inherited from Zen Cart. See [`LICENSE`](LICENSE) for the
full terms.

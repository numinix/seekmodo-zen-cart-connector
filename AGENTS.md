# AGENTS.md — Seekmodo Zen Cart connector

Rules for AI agents and operators working in this repository.

## Publishing a release to seekmodo.com

Every connector release that merchants download from
<https://seekmodo.com/plugins/zen-cart> must update **all** of the
following. Skipping any step leaves the public download page stale.

### 1. Connector code + changelog (this repo)

- [ ] Bump `pluginVersion` in `zc_plugins/Seekmodo/vX.Y.Z/manifest.php` when the **plugin code** changes.
- [ ] Add an entry to [`CHANGELOG.md`](CHANGELOG.md) and
      `zc_plugins/Seekmodo/vX.Y.Z/CHANGELOG.md`.
- [ ] Update [`connector-docs/zen-cart.yaml`](connector-docs/zen-cart.yaml) if install/usage steps changed.

### 2. Build the signed zip

From this repo root (requires Node.js, Python + PyYAML, Composer, and
the operator release-signing key):

```bash
python tools/build_release.py \
  --manifest-path ../seekmodo/services/marketing-site/public/plugins/manifest.json
```

For a **docs-only repack** at the same plugin version (README.html /
license.txt / packaging — no PHP changes):

```bash
python tools/build_release.py \
  --skip-key-vendor \
  --manifest-path ../seekmodo/services/marketing-site/public/plugins/manifest.json
```

Use `--skip-docs` only in emergencies. Never ship without production
signing (`signed_with` must not be `dev-ephemeral`).

### 3. Marketing-site changelog (seekmodo monorepo)

**Required.** The download page changelog is **not** generated from
this repo. After every publish, update the `VERSION_HISTORY` array in:

`seekmodo/services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx`

Add a row for the released version (newest first) with date + a
one-paragraph merchant-facing summary distilled from `CHANGELOG.md`.
The page reads `manifest.json` for the download button but **hardcodes**
version history — if you skip this step, seekmodo.com shows an outdated
changelog even when the zip is current.

Also sync [`seekmodo/services/marketing-site/content/support-kb/site-plugins-zen-cart.md`](../seekmodo/services/marketing-site/content/support-kb/site-plugins-zen-cart.md) when the download URL or install steps change.

### 4. Deploy

Commit the zip + sidecars + `manifest.json` + marketing-site page
changes to `numinix/seekmodo` `main`. The seek-api01 push webhook
redeploys marketing-site (~2 minutes).

Alternatively: `python tools/build_release.py --auto-pr` opens an
auto-merging PR into `numinix/seekmodo` (still requires step 3 for
`VERSION_HISTORY` in a follow-up commit if not done in the same PR).

## Publishing a release to Numinix.com

Merchants can also download from **Numinix.com** (product
`Seekmodo for Zen Cart`, `products_id=2044`). Numinix packages its own
zip via `Numinix\PluginRelease\Releaser` — separate from the signed
seekmodo.com artefact.

### When to run

After the connector tag is on GitHub (`git push origin vX.Y.Z`) and
seekmodo.com publish (steps 1–4 above) are complete:

```bash
python tools/publish_numinix_release.py --tag vX.Y.Z
```

This calls `release_plugin` on `https://www.numinix.com/mcp/`, which
clones `numinix/seekmodo-zen-cart-connector`, creates the git tag,
archives into `free_download_manager/`, inserts a
`free_download_manager` row, and updates `products_model`.

### Auth

Set `NUMINIX_MCP_BEARER` or rely on
`numinix.com-local/config/server-access.local.json` →
`mcp_plugin_release.bearer_token`.

### Initial product setup (one-time)

Scripts live in `numinix.com-local/scripts/`:

- `setup-seekmodo-zen-cart-product.php` — product row, categories,
  attributes, git_url
- `fix-seekmodo-zen-cart-attributes.php` — repair helper if attribute
  clone fails

Product image: `images/seekmodo-for-zen-cart.png` (from
seekmodo.com plugin banner assets).

Categories / filter facets: Free Zen Cart Plugins (182), Zen Cart
Search Modules (197), Zen Cart Plugins (219), plus Platform / Plugin
Type display attributes for category listing filters.

Optional **$130 one-time installation** uses Installation option 22
(`attributes_price_onetime=130` on value “Installation”, default
“I will install myself”).

## Where to look first

| Task | Path |
|------|------|
| Install guide (source) | [`docs/INSTALL.md`](docs/INSTALL.md) |
| Zip documentation template | [`../seekmodo/packages/connector-docs/`](../seekmodo/packages/connector-docs/) |
| Download manifest | [`../seekmodo/services/marketing-site/public/plugins/manifest.json`](../seekmodo/services/marketing-site/public/plugins/manifest.json) |
| Public changelog UI | [`../seekmodo/services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx`](../seekmodo/services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx) |

## Suggest transport

Default storefront boot stamps `seekmodo:suggest-proxy` at
`numinix_seekmodo_suggest.php?seekmodo_action=rich-suggest` (HMAC
server-side). Gateway-direct remains opt-in:

```php
define('NUMINIX_SEEKMODO_SUGGEST_GATEWAY_DIRECT', 'true');
```

Sync the vendored bundle after rebuilding web-components into the
latest `zc_plugins/Seekmodo/v*/catalog/.../jscript/seekmodo_suggest.bundle.js`.

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

## Where to look first

| Task | Path |
|------|------|
| Install guide (source) | [`docs/INSTALL.md`](docs/INSTALL.md) |
| Zip documentation template | [`../seekmodo/packages/connector-docs/`](../seekmodo/packages/connector-docs/) |
| Download manifest | [`../seekmodo/services/marketing-site/public/plugins/manifest.json`](../seekmodo/services/marketing-site/public/plugins/manifest.json) |
| Public changelog UI | [`../seekmodo/services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx`](../seekmodo/services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx) |

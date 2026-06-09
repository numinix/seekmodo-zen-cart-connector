# Seekmodo Zen Cart connector — v1.0.18 changelog

## Summary

v1.0.18 is the **signing-key rotation release**. The connector code
itself is unchanged from v1.0.17 — every new behavior in this drop
sits in the build/publish pipeline + the per-version
`admin/release-signing.pub` JWK. The point of cutting v1.0.18 is to
finally vendor a non-placeholder, non-`dev-ephemeral` trust root into
the plugin so the in-plugin auto-updater
(`UpdateClient::verifySignature()`) can do its job, which it has
never been able to do in any released version up through v1.0.13.

## What's in the zip that changed vs v1.0.17

### `admin/release-signing.pub` — real vendored pubkey

The JWK now contains a stable ed25519 public key:

```json
{
  "kid": "seekmodo-2026-06",
  "x":   "ZgxMqIosTOONLmnJKzbaf45LOOYCfCITytNB1qKyAMk"
}
```

Compare to what shipped previously:

| Version           | Vendored `kid`   | Vendored `x` (truncated) | Usable trust root? |
| ----------------- | ---------------- | ------------------------ | ------------------ |
| v1.0.13 (live)    | `marketing-2026-05` | `PLACEHOLDER_REPLACED_BY_…` | No (won't decode)  |
| v1.0.14–v1.0.16   | `dev-ephemeral`  | varies by build host     | No (verifier refuses) |
| v1.0.17 (live)    | `dev-ephemeral`  | `WFAPvJk…0Cw` (lost) | No (verifier refuses) |
| **v1.0.18**       | **`seekmodo-2026-06`** | `ZgxMqIos…ksMk` | **Yes**            |

`UpdateClient::loadVendoredPublicKey()` now returns a real keypair
dict for the first time in this connector's history.
`UpdateClient::verifySignature()` reaches its ed25519 verification
step instead of bouncing early on the `signed_with === 'dev-ephemeral'`
gate.

### `manifest.php` — version bump only

`pluginVersion => 'v1.0.18'`. No schema changes.

## Operator action: manual upgrade required for the live fleet

Whichever Seekmodo version your store is currently on (v1.0.13 from
PR #45, or v1.0.17 from PR #60), it cannot auto-update to v1.0.18 —
the in-plugin verifier raises one of:

- **v1.0.13:** "no vendored public key found at …" (the vendored
  pubkey is the literal `PLACEHOLDER_REPLACED_BY_BUILD_RELEASE_PY`
  string and won't decode).
- **v1.0.14 – v1.0.17:** "manifest sig_kid (seekmodo-2026-06) !=
  vendored kid (dev-ephemeral); manual upgrade required to rotate
  keys" (the verifier's rotation safety gate, by design).

**Cutover procedure** (per store, ~5 minutes):

1. Backup the current `zc_plugins/Seekmodo/v1.0.<current>/` tree.
2. Download `seekmodo-zen-cart-v1.0.18.zip` from
   <https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.18.zip>.
3. Verify the SHA-256 against
   <https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.18.zip.sha256>
   and the ed25519 signature against
   <https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.18.zip.sig>
   using the published JWKS at
   <https://seekmodo.com/.well-known/release-signing-keys.json>.
4. Extract on the store; the top-level structure is
   `zc_plugins/Seekmodo/v1.0.18/`.
5. Install via Zen Cart admin → Plugin Manager → Seekmodo →
   "Install" (the existing tenant snapshot, admin keys, and APCu
   caches survive — the upgrade is files-only).
6. Confirm the Updates page now shows "Up to date (latest:
   v1.0.18)" rather than a refusal banner.

From v1.0.18 on, future releases (v1.0.19, v1.0.20, …) will appear
on the same Updates page and apply automatically as long as
seekmodo.com keeps signing under `seekmodo-2026-06` (or a future
kid that we've vendored into a subsequent connector release).

## Carry-over from v1.0.17

For the underlying connector behavior (SKU exact-match boost,
expanded fallback handling, etc.), see the v1.0.17 CHANGELOG — none
of that surface area changes in v1.0.18.

"""Build a Zen Cart connector release zip from the plugin tree.

Repo: github.com/numinix/seekmodo-zen-cart-connector

Produces a Zen Cart-compatible plugin zip plus a SHA-256 sidecar, an
ed25519 detached signature (for Sprint 4 in-plugin auto-update), and
(optionally) a ``manifest.json`` entry suitable for publishing to the
seekmodo monorepo's ``services/marketing-site/public/plugins/``
directory.

================================================================
ROUTINE RELEASES ARE OPERATOR-LOCAL.
================================================================

Per the seekmodo monorepo's ``.cursor/rules/deploy.mdc`` + AGENTS.md
§1, GitHub Actions is not used for routine deploys (it costs us
billable minutes). The routine release path is operator-local:

    git tag v<X.Y.Z>
    git push origin v<X.Y.Z>
    python tools/build_release.py --auto-pr

``--auto-pr`` opens a PR against ``numinix/seekmodo`` carrying the
new manifest entry + zip under
``services/marketing-site/public/plugins/``. When that PR merges to
``main``, the seek-api01 deploy webhook sees the
``services/marketing-site/**`` change and redeploys marketing-site,
publishing the new download at
``https://seekmodo.com/plugins/seekmodo-zen-cart-v<X.Y.Z>.zip``
within ~2 minutes. The *deployment* is webhook-driven; only the
artefact build is operator-local. **No GHA minutes are consumed.**

The legacy ``.github/workflows/release.yml`` is retained as a
``workflow_dispatch``-only emergency fallback for the case where an
operator can't run ``tools/build_release.py`` from their workstation
(e.g. ed25519 signing key unavailable locally) and needs GHA to
build + sign + open the PR. It is not the routine path.

The version is read from the highest-numbered ``v*/manifest.php``'s
``pluginVersion`` field.

Run from the connector repo root:

    python tools/build_release.py
    python tools/build_release.py --bump patch
    python tools/build_release.py --manifest-path manifest.json
    python tools/build_release.py --auto-pr   # opens a PR into seekmodo

ED25519 signing (Sprint 4 PR 1):

The signing key is a PEM-encoded ed25519 private key on the build
host.  In CI it lives at ``seek-api01:/etc/numinix/release-signing.key``
(also accepted at the legacy ``/etc/numinix/marketing-jwt.ed25519``
path so we can re-use the existing M5 keypair).  Locally you can
override the path with ``RELEASE_SIGNING_KEY_PATH=/path/to/key``;
when no key is found the script signs with an in-memory ephemeral
keypair and embeds a warning ``signed_with: dev-ephemeral`` flag in
the manifest.json so we never silently ship an unverifiable build
to production.

The corresponding ed25519 public key is published two ways:

  * As a JWK in ``https://seekmodo.com/.well-known/jwks.json`` (the
    marketing site's existing JWKS surface — same key that signs
    pairing JWTs).  Used by the connector's in-plugin "Check for
    updates" verifier (Sprint 4 PR 2).
  * As a vendored copy inside each connector release tree at
    ``zc_plugins/Seekmodo/<vN>/admin/release-signing.pub`` so an
    attacker cannot swap the manifest.json + JWKS in flight without
    also tampering with the plugin's own bundled key.

The signature itself is computed over the raw bytes of the connector
release zip (the same bytes whose SHA-256 lives in
``manifest.json#sha256``).  It's emitted as a detached
``<zip>.sig`` sidecar AND as a ``sig`` field in the manifest entry,
both base64-url-encoded.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any

try:
    # cryptography ships with most CI runners; we only need the
    # tiny ed25519 surface from it. Falls back to PyNaCl below if
    # this import fails (some Alpine-based CI runners ship PyNaCl
    # but not cryptography).
    from cryptography.hazmat.primitives import serialization as _crypto_serialization
    from cryptography.hazmat.primitives.asymmetric.ed25519 import (
        Ed25519PrivateKey,
        Ed25519PublicKey,
    )
    _CRYPTO_BACKEND = "cryptography"
except Exception:  # pragma: no cover — exercised on slim CI runners
    _crypto_serialization = None
    Ed25519PrivateKey = None  # type: ignore[assignment]
    Ed25519PublicKey = None  # type: ignore[assignment]
    _CRYPTO_BACKEND = None
    try:
        from nacl.signing import SigningKey as _NaclSigningKey  # type: ignore[import-not-found]
        _CRYPTO_BACKEND = "pynacl"
    except Exception:
        _NaclSigningKey = None  # type: ignore[assignment]

REPO_ROOT = Path(__file__).resolve().parent.parent
PLUGIN_ROOT = REPO_ROOT / "zc_plugins" / "Seekmodo"
MANIFEST_RE = re.compile(r"'pluginVersion'\s*=>\s*'v(\d+)\.(\d+)\.(\d+)'")
DIST_DIR = REPO_ROOT / "dist"
CONNECTOR_DOCS_YAML = REPO_ROOT / "connector-docs" / "zen-cart.yaml"
LICENSE_TXT = REPO_ROOT / "license.txt"
RENDER_DOCS_SCRIPT = (
    Path(os.environ.get("SEEKMODO_MONOREPO_ROOT", REPO_ROOT.parent / "seekmodo"))
    / "tools"
    / "render_connector_docs.mjs"
)

# Where the ed25519 release-signing private key lives in production.
# In dev/local we accept a RELEASE_SIGNING_KEY_PATH env override;
# without one we walk a priority list of well-known paths, preferring
# the kid-namespaced path so future rotations can stage a new key on
# disk before flipping `_RELEASE_SIGNING_KID`, with no clobbering of
# the prior key. Only after exhausting every path do we fall back to
# an ephemeral in-memory keypair (which the in-plugin UpdateClient
# refuses outright via the `dev-ephemeral` flag, so production CI
# **MUST** land in one of the file paths below).
_RELEASE_SIGNING_KID = "seekmodo-2026-06-r2"
_RELEASE_SIGNING_KEY_DEFAULT = "/etc/numinix/release-signing.key"
_RELEASE_SIGNING_KEY_LEGACY = "/etc/numinix/marketing-jwt.ed25519"

# Canonical public key for `_RELEASE_SIGNING_KID` (JWK ``x``,
# base64url-nopad). Build hosts that resolve *any* key file under the
# kid-namespaced path used to stamp this kid onto whatever seed was
# present — v1.3.27..v1.3.30 shipped with a wrong ``x`` labeled as
# ``seekmodo-2026-06-r2``, which broke in-plugin upgrades. Pin the
# expected material so a mis-named private key fails the build.
_RELEASE_SIGNING_PUB_X = "ozNs5QQUhP6YNjE_KffhJqYtDQL8m2mHzWNivlhgoPA"

# Note the LEGACY pre-rotation kid we still recognize in old manifests
# / vendored pubkeys. Surfaced here for the operator-facing key-
# inventory section of docs/SIGNING_KEYS.md, not consumed by this
# script.  Earlier builds incorrectly labeled the seekmodo
# release-signing key as `marketing-2026-05`; that label is dead.
_LEGACY_RELEASE_SIGNING_KID = "marketing-2026-05"

# Public download base. Every release zip lives at
# ``<DOWNLOAD_BASE>/<basename>``; the manifest references this URL so
# in-plugin auto-update can consume it directly. Sprint 2 PR 3 plans
# this; Sprint 4 implements the consumer side.
DOWNLOAD_BASE = "https://seekmodo.com/plugins"

# Which gateway version this connector requires at minimum. Bumped
# when the connector starts depending on a tenant.snapshot field
# the gateway only emits at >= some version.
MIN_COMPATIBLE_GATEWAY = "0.1.0"

# Which target seekmodo monorepo + branch the auto-PR lands in.
SEEKMODO_REPO = "numinix/seekmodo"
SEEKMODO_DEFAULT_BRANCH = "main"
SEEKMODO_MANIFEST_REL = "services/marketing-site/public/plugins/manifest.json"
SEEKMODO_PLUGINS_DIR_REL = "services/marketing-site/public/plugins"
SEEKMODO_VERSION_HISTORY_PAGE_REL = (
    "services/marketing-site/app/(marketing)/plugins/zen-cart/page.tsx"
)
SYNC_VERSION_HISTORY_SCRIPT = (
    Path(os.environ.get("SEEKMODO_MONOREPO_ROOT", REPO_ROOT.parent / "seekmodo"))
    / "tools"
    / "sync_connector_version_history.py"
)


def _run(cmd: list[str], cwd: Path | None = None, check: bool = True, env: dict | None = None) -> subprocess.CompletedProcess:
    """Thin subprocess.run wrapper that prints the command first."""
    print("  $ " + " ".join(cmd))
    result = subprocess.run(
        cmd,
        cwd=str(cwd) if cwd else None,
        check=False,
        env={**os.environ, **(env or {})},
        capture_output=True,
        text=True,
    )
    if result.stdout:
        print(result.stdout.rstrip())
    if result.stderr:
        print(result.stderr.rstrip(), file=sys.stderr)
    if check and result.returncode != 0:
        raise subprocess.CalledProcessError(result.returncode, cmd, result.stdout, result.stderr)
    return result


def discover_current_version() -> tuple[Path, tuple[int, int, int]]:
    """Find the highest-numbered v*/manifest.php under the plugin root."""
    versions: list[tuple[tuple[int, int, int], Path]] = []
    for entry in PLUGIN_ROOT.iterdir():
        if not entry.is_dir():
            continue
        m = re.fullmatch(r"v(\d+)\.(\d+)\.(\d+)", entry.name)
        if m is None:
            continue
        manifest = entry / "manifest.php"
        if not manifest.is_file():
            continue
        versions.append(((int(m.group(1)), int(m.group(2)), int(m.group(3))), entry))
    if not versions:
        print(f"ERROR: no v*.*.* directories under {PLUGIN_ROOT}", file=sys.stderr)
        sys.exit(2)
    versions.sort()
    triple, path = versions[-1]
    return path, triple


def bump(triple: tuple[int, int, int], part: str) -> tuple[int, int, int]:
    major, minor, patch = triple
    if part == "major":
        return (major + 1, 0, 0)
    if part == "minor":
        return (major, minor + 1, 0)
    if part == "patch":
        return (major, minor, patch + 1)
    raise ValueError(f"unknown bump part: {part}")


def write_manifest_version(manifest: Path, triple: tuple[int, int, int]) -> None:
    raw = manifest.read_text(encoding="utf-8")
    new_str = f"v{triple[0]}.{triple[1]}.{triple[2]}"
    if not MANIFEST_RE.search(raw):
        raise SystemExit(f"ERROR: pluginVersion not found in {manifest}")
    raw = MANIFEST_RE.sub(f"'pluginVersion' => '{new_str}'", raw)
    manifest.write_text(raw, encoding="utf-8", newline="\n")
    print(f"  manifest.php updated -> pluginVersion={new_str}")


# ---------------------------------------------------------------------------
# SDK vendoring — Phase 3 of the PHP SDK + connector migration.
#
# The shared numinix/seekmodo-connector SDK lives in its own repository
# (sibling at ../seekmodo-php-sdk / package numinix/seekmodo-connector).
# The connector itself stays composer-free at runtime (Zen Cart hosts
# rarely have composer; we don't want to require it).
#
# At BUILD time we run `composer install --no-dev` at the connector
# repo root, then copy the SDK's `vendor/numinix/seekmodo-connector/src/`
# tree into the per-version plugin directory at
# `catalog/includes/library/Numinix/SeekmodoSdk/`. The plugin's manual
# PSR-4 autoloader (init_numinix_seekmodo.php) picks the classes up at
# runtime with zero ceremony.
#
# We deliberately vendor under the namespace's PSR-4 root path
# (Numinix\SeekmodoSdk\ -> library/Numinix/SeekmodoSdk/) — the same
# convention the autoloader expects for the legacy Numinix\Seekmodo\
# tree. The SDK has its own namespace root (Numinix\SeekmodoSdk\) so
# it never collides with the connector's own Numinix\Seekmodo\* classes.
# ---------------------------------------------------------------------------

SDK_PACKAGE = "numinix/seekmodo-connector"
SDK_VENDOR_REL = Path("vendor") / "numinix" / "seekmodo-connector" / "src"
SDK_DEST_REL = Path("catalog") / "includes" / "library" / "Numinix" / "SeekmodoSdk"


def vendor_sdk(version_dir: Path) -> int:
    """Vendor `numinix/seekmodo-connector` into the per-version plugin tree.

    Returns the number of PHP files copied. Skips silently (with a clear
    notice) when `composer` is not on PATH — local devs without composer
    can still build the legacy class-only zip, the SDK directory just
    stays empty and the SDK-dependent code paths short-circuit.
    """
    print(f"-- vendoring {SDK_PACKAGE} into {SDK_DEST_REL.as_posix()}")
    dest = version_dir / SDK_DEST_REL
    if dest.is_dir() and any(dest.glob("*.php")):
        count = sum(1 for _ in dest.rglob("*.php"))
        print(f"  SDK already present in plugin tree ({count} PHP file(s)); skipping composer.")
        return count

    sdk_root = os.environ.get("SEEKMODO_PHP_SDK_ROOT", "").strip()
    if sdk_root:
        sdk_src = Path(sdk_root) / "src"
        if sdk_src.is_dir():
            print(f"  using SEEKMODO_PHP_SDK_ROOT={sdk_root}")
            return _copy_sdk_tree(version_dir, sdk_src)

    composer = shutil.which("composer") or shutil.which("composer.phar")
    composer_json = REPO_ROOT / "composer.json"
    if not composer_json.is_file():
        print("  WARN: no composer.json at repo root; skipping SDK vendoring.")
        return 0
    if composer is None:
        print("  WARN: composer not on PATH; skipping SDK vendoring.")
        print("        Install from https://getcomposer.org/ to ship the SDK in the zip.")
        return 0

    _run([composer, "install", "--no-dev", "--no-interaction", "--prefer-dist"], cwd=REPO_ROOT)

    sdk_src = REPO_ROOT / SDK_VENDOR_REL
    if not sdk_src.is_dir():
        raise SystemExit(
            f"ERROR: expected {sdk_src} after composer install — package missing from composer.json?"
        )
    return _copy_sdk_tree(version_dir, sdk_src)


def _copy_sdk_tree(version_dir: Path, sdk_src: Path) -> int:
    """Copy PHP files from an SDK src tree into the per-version plugin tree."""
    dest = version_dir / SDK_DEST_REL
    # Clean the dest so a downgraded SDK doesn't leave stale files behind.
    if dest.is_dir():
        for child in dest.iterdir():
            if child.name == ".gitkeep":
                continue
            if child.is_dir():
                shutil.rmtree(child)
            else:
                child.unlink()
    else:
        dest.mkdir(parents=True, exist_ok=True)

    copied = 0
    for path in sorted(sdk_src.rglob("*")):
        if path.is_dir():
            continue
        if path.suffix != ".php":
            continue
        rel = path.relative_to(sdk_src)
        out = dest / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, out)
        copied += 1

    rel_dest = dest.relative_to(REPO_ROOT)
    print(f"  vendored {copied} SDK file(s) into {rel_dest.as_posix()}")
    return copied


def build_zip(version_dir: Path, version: str) -> Path:
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    out = DIST_DIR / f"seekmodo-zen-cart-{version}.zip"
    if out.exists():
        out.unlink()

    # Plugin Manager expects the zip's top-level folder to be the
    # plugin name (Seekmodo) with a versioned subfolder underneath.
    rel_root = Path("zc_plugins") / "Seekmodo" / version

    skipped: list[str] = []
    written = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(version_dir.rglob("*")):
            if path.is_dir():
                continue
            if path.name == ".DS_Store":
                continue
            if any(part.startswith(".") for part in path.relative_to(version_dir).parts):
                skipped.append(str(path.relative_to(version_dir)))
                continue
            arcname = (rel_root / path.relative_to(version_dir)).as_posix()
            zf.write(path, arcname)
            written += 1

    rel = out.relative_to(REPO_ROOT)
    print(f"  built {rel} ({written} files, {out.stat().st_size:,} bytes)")
    if skipped:
        print(f"  skipped {len(skipped)} dotfile(s)")
    return out


def render_connector_docs(version: str) -> Path:
    """Render branded README.html + assets/ via the seekmodo monorepo tool."""
    if not CONNECTOR_DOCS_YAML.exists():
        raise FileNotFoundError(
            f"connector docs content missing: {CONNECTOR_DOCS_YAML}"
        )
    if not RENDER_DOCS_SCRIPT.exists():
        raise FileNotFoundError(
            f"render_connector_docs.mjs missing: {RENDER_DOCS_SCRIPT}\n"
            "Set SEEKMODO_MONOREPO_ROOT to the numinix/seekmodo checkout."
        )
    out_dir = Path(tempfile.mkdtemp(prefix="seekmodo-connector-docs-"))
    cmd = [
        "node",
        str(RENDER_DOCS_SCRIPT),
        "--content",
        str(CONNECTOR_DOCS_YAML),
        "--version",
        version,
        "--output",
        str(out_dir),
    ]
    print(f"  render docs: {' '.join(cmd)}")
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print(result.stdout, end="")
        print(result.stderr, end="", file=sys.stderr)
        raise RuntimeError(f"connector docs render failed (exit {result.returncode})")
    if result.stdout.strip():
        print(f"  {result.stdout.strip()}")
    readme = out_dir / "README.html"
    if not readme.exists():
        raise RuntimeError(f"docs render did not produce README.html in {out_dir}")
    return out_dir


def inject_docs_into_zip(zip_path: Path, doc_root: Path) -> int:
    """Append README.html, license.txt, and assets/ to an existing release zip."""
    written = 0
    with zipfile.ZipFile(zip_path, "a", zipfile.ZIP_DEFLATED) as zf:
        readme = doc_root / "README.html"
        zf.write(readme, "README.html")
        written += 1
        if LICENSE_TXT.exists():
            zf.write(LICENSE_TXT, "license.txt")
            written += 1
        else:
            print(f"  WARN: license.txt missing at {LICENSE_TXT}", file=sys.stderr)
        assets_dir = doc_root / "assets"
        if assets_dir.is_dir():
            for path in sorted(assets_dir.rglob("*")):
                if path.is_dir():
                    continue
                arcname = ("assets/" + path.relative_to(assets_dir).as_posix())
                zf.write(path, arcname)
                written += 1
    print(f"  injected {written} doc file(s) at zip root")
    return written


def write_sha256_sidecar(zip_path: Path) -> tuple[Path, str]:
    """Write a `<zip>.sha256` sidecar in the GNU coreutils format and
    return (sidecar_path, hex_digest)."""
    h = hashlib.sha256()
    with zip_path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 16), b""):
            h.update(chunk)
    digest = h.hexdigest()
    sidecar = zip_path.with_suffix(zip_path.suffix + ".sha256")
    sidecar.write_text(
        f"{digest}  {zip_path.name}\n",
        encoding="utf-8",
        newline="\n",
    )
    print(f"  wrote {sidecar.relative_to(REPO_ROOT)} ({digest[:12]}...)")
    return sidecar, digest


def _b64url_nopad(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode("ascii")


def _assert_production_pubkey(public_raw: bytes, key_path: Path) -> None:
    """Refuse to label a build as ``_RELEASE_SIGNING_KID`` unless the
    derived public key matches the pinned production ``x`` value."""
    got = _b64url_nopad(public_raw)
    if got != _RELEASE_SIGNING_PUB_X:
        raise SystemExit(
            f"ERROR: {key_path} derives public key x={got}, but "
            f"kid {_RELEASE_SIGNING_KID} requires x={_RELEASE_SIGNING_PUB_X}. "
            "Refusing to stamp the production kid onto a mismatched key "
            "(this is how v1.3.27..v1.3.30 shipped a wrong trust root)."
        )


def _resolve_signing_key_path() -> Path | None:
    """Resolve the on-disk release-signing private key path or None
    if we should fall back to an ephemeral keypair.  Honours
    RELEASE_SIGNING_KEY_PATH override, falls through to the canonical
    /etc/numinix path, then the legacy marketing-jwt path."""
    override = os.environ.get("RELEASE_SIGNING_KEY_PATH", "").strip()
    home = Path.home()
    candidates = [
        override,
        # Kid-namespaced paths (preferred).
        str(home / ".numinix" / f"release-signing-{_RELEASE_SIGNING_KID}.key"),
        f"/etc/numinix/release-signing-{_RELEASE_SIGNING_KID}.key",
        # Legacy unnamed paths (fallback for unrotated builders).
        _RELEASE_SIGNING_KEY_DEFAULT,
        _RELEASE_SIGNING_KEY_LEGACY,
        str(home / ".numinix" / "release-signing.key"),
    ]
    for raw in candidates:
        if not raw:
            continue
        p = Path(raw)
        if p.is_file():
            return p
    return None


def _decode_seed_bytes(raw: bytes, key_path: Path) -> bytes | None:
    """Try to interpret a key file's bytes as a 32-byte ed25519 seed.

    Recognized formats:
      - 32 bytes raw seed
      - 64 ASCII hex chars (32-byte seed, hex-encoded)
      - 44 ASCII base64 chars with padding, or 43 b64url-nopad chars

    Returns the 32-byte seed or None if the bytes don't fit any of
    these shapes (PEM and other formats are handled separately).
    """
    candidate = raw.strip()
    if len(candidate) == 32:
        return candidate
    if len(candidate) == 64:
        try:
            return bytes.fromhex(candidate.decode("ascii"))
        except (ValueError, UnicodeDecodeError):
            return None
    text = candidate.decode("ascii", errors="ignore")
    if len(text) in (43, 44) and re.fullmatch(r"[A-Za-z0-9+/_=-]+", text):
        pad = "=" * ((4 - len(text) % 4) % 4)
        try:
            decoded = base64.urlsafe_b64decode(text + pad)
        except Exception:
            try:
                decoded = base64.b64decode(text + pad)
            except Exception:
                return None
        return decoded if len(decoded) == 32 else None
    return None


def _load_or_generate_signing_key() -> tuple[Any, Any, str, str]:
    """Returns (signer, public_bytes_raw, key_source_label, kid).

    ``signer`` is an opaque object whose only contract is that it has
    a ``sign(bytes) -> bytes`` method returning the raw ed25519
    signature.  ``public_bytes_raw`` is the 32-byte raw ed25519 public
    key (suitable for vendoring into the plugin or publishing as a
    JWK ``x`` field).  ``key_source_label`` is one of
    ``"file:<path>"`` or ``"dev-ephemeral"`` so the manifest can flag
    unverifiable builds.

    Backend selection:
      1. cryptography (preferred) — handles PEM ed25519 keys *and*
         raw / hex / base64-encoded 32-byte seeds.
      2. pynacl (fallback) — handles raw / hex / base64 seeds only.
      3. ephemeral (dev fallback) — generates a fresh keypair so the
         pipeline keeps producing a sig, but flags the build as
         dev-only.

    The WP connector's ``tools/build_release.py`` accepts the same
    file formats; keep these two scripts in sync so an operator can
    use a single ``~/.numinix/release-signing-<kid>.key`` for both.
    """
    key_path = _resolve_signing_key_path()
    if _CRYPTO_BACKEND == "cryptography" and key_path is not None:
        raw = key_path.read_bytes()
        seed = _decode_seed_bytes(raw, key_path)
        if seed is not None:
            priv = Ed25519PrivateKey.from_private_bytes(seed)  # type: ignore[union-attr]
        else:
            try:
                priv = _crypto_serialization.load_pem_private_key(raw, password=None)
            except ValueError as exc:
                raise SystemExit(
                    f"ERROR: {key_path} is neither a 32-byte ed25519 seed "
                    f"(raw / hex / base64) nor a loadable PEM private key: {exc}"
                )
            if not isinstance(priv, Ed25519PrivateKey):  # type: ignore[arg-type]
                raise SystemExit(
                    f"ERROR: {key_path} is not an ed25519 PEM private key."
                )
        pub = priv.public_key().public_bytes(
            encoding=_crypto_serialization.Encoding.Raw,
            format=_crypto_serialization.PublicFormat.Raw,
        )
        _assert_production_pubkey(pub, key_path)

        class _CryptographySigner:
            def sign(self, data: bytes) -> bytes:
                return priv.sign(data)

        return (
            _CryptographySigner(),
            pub,
            f"file:{key_path}",
            _RELEASE_SIGNING_KID,
        )

    if _CRYPTO_BACKEND == "pynacl" and key_path is not None:
        raw = key_path.read_bytes()
        seed = _decode_seed_bytes(raw, key_path)
        if seed is None:
            if raw.strip().startswith(b"-----BEGIN"):
                raise SystemExit(
                    f"ERROR: PyNaCl backend cannot read PEM key {key_path}; "
                    "install `cryptography` on the build host."
                )
            raise SystemExit(
                f"ERROR: expected a 32-byte ed25519 seed (raw / hex / base64) at {key_path}."
            )
        signing = _NaclSigningKey(seed)  # type: ignore[misc]
        pub = bytes(signing.verify_key)
        _assert_production_pubkey(pub, key_path)

        class _NaclSigner:
            def sign(self, data: bytes) -> bytes:
                return signing.sign(data).signature  # type: ignore[no-any-return]

        return _NaclSigner(), pub, f"file:{key_path}", _RELEASE_SIGNING_KID

    # Dev-ephemeral fallback.  Surface this loudly — production CI
    # MUST NOT land here, and the manifest entry will carry a
    # `signed_with: dev-ephemeral` flag the in-plugin verifier
    # (Sprint 4 PR 2) refuses on production.
    print(
        "  WARN: no release-signing key found; generating an ephemeral "
        "keypair for this build (manifest will be flagged dev-ephemeral)."
    )
    if _CRYPTO_BACKEND == "cryptography":
        ephemeral_priv = Ed25519PrivateKey.generate()  # type: ignore[union-attr]
        pub = ephemeral_priv.public_key().public_bytes(
            encoding=_crypto_serialization.Encoding.Raw,
            format=_crypto_serialization.PublicFormat.Raw,
        )

        class _CryptographyEphemeralSigner:
            def sign(self, data: bytes) -> bytes:
                return ephemeral_priv.sign(data)

        return _CryptographyEphemeralSigner(), pub, "dev-ephemeral", "dev-ephemeral"
    if _CRYPTO_BACKEND == "pynacl":
        signing = _NaclSigningKey.generate()  # type: ignore[union-attr]
        pub = bytes(signing.verify_key)

        class _NaclEphemeralSigner:
            def sign(self, data: bytes) -> bytes:
                return signing.sign(data).signature  # type: ignore[no-any-return]

        return _NaclEphemeralSigner(), pub, "dev-ephemeral", "dev-ephemeral"
    raise SystemExit(
        "ERROR: no ed25519 backend available — install either "
        "`cryptography` or `pynacl` on the build host."
    )


def write_detached_signature_for_zip(zip_path: Path, signer: Any) -> tuple[Path, str]:
    """Sign the zip's bytes and write a ``<zip>.sig`` sidecar.

    Returns ``(sidecar_path, signature_b64url_nopad)``. Separated from
    the keypair-resolution step so callers can vendor the pubkey
    BEFORE building the zip (so the zip ships the right trust root),
    then sign the finished zip AFTER. See main() for the order.
    """
    payload = zip_path.read_bytes()
    sig = signer.sign(payload)
    sig_b64 = _b64url_nopad(sig)
    sidecar = zip_path.with_suffix(zip_path.suffix + ".sig")
    sidecar.write_text(sig_b64 + "\n", encoding="utf-8", newline="\n")
    return sidecar, sig_b64


def vendor_public_key(version_dir: Path, public_raw: bytes, kid: str) -> Path:
    """Drop a copy of the ed25519 public key into the per-version
    plugin tree at ``admin/release-signing.pub`` so the plugin's
    in-page Apply-update verifier can verify against the bundled
    public key (defence in depth: an attacker who compromised the
    JWKS endpoint still has to compromise the plugin's own files).
    Stores both the raw ``x`` value (base64-url) and the ``kid`` so
    a future key rotation can be detected."""
    target = version_dir / "admin" / "release-signing.pub"
    target.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "kid": kid,
        "alg": "EdDSA",
        "kty": "OKP",
        "crv": "Ed25519",
        "x": _b64url_nopad(public_raw),
    }
    target.write_text(
        json.dumps(payload, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    return target


def update_manifest_json(
    manifest_path: Path,
    version: str,
    sha256: str,
    zip_basename: str,
    sig_b64: str | None = None,
    sig_kid: str | None = None,
    sig_source: str | None = None,
) -> None:
    """Update or create a `manifest.json` describing the latest release.

    Schema (Sprint 4 PR 1):

        {
          "platforms": {
            "zen_cart": {
              "latest": "1.0.7",
              "url": "https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.7.zip",
              "sha256": "...",
              "released_at": "2026-...",
              "release_notes_url": "https://seekmodo.com/plugins/zen-cart#changelog",
              "min_compatible_gateway": "0.1.0",
              "sig": "<base64url-nopad ed25519 signature of the zip's bytes>",
              "sig_kid": "marketing-2026-05",
              "signed_at": "2026-...",
              "signed_with": "file:/etc/numinix/release-signing.key"
            }
          }
        }

    The `sig` / `sig_kid` / `signed_at` triple lets the connector's
    in-plugin auto-update verifier (Sprint 4 PR 2) fetch the
    manifest, verify the signature against the bundled public key
    (vendored under `admin/release-signing.pub`) and / or the JWKS
    surface, and only then offer the upgrade.

    `signed_with` is informational — production builds set it to a
    file path, dev-ephemeral builds set it to ``"dev-ephemeral"`` so
    the plugin verifier can refuse them outright.
    """
    if manifest_path.is_file():
        try:
            data = json.loads(manifest_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            print(f"  WARN: {manifest_path} is not valid JSON; rewriting from scratch.")
            data = {}
    else:
        data = {}
    data.setdefault("platforms", {})
    bare_version = version.lstrip("v")
    now = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")
    entry: dict[str, Any] = {
        "latest": bare_version,
        "url": f"{DOWNLOAD_BASE}/{zip_basename}",
        "sha256": sha256,
        "released_at": now,
        "release_notes_url": "https://seekmodo.com/plugins/zen-cart#changelog",
        "min_compatible_gateway": MIN_COMPATIBLE_GATEWAY,
    }
    if sig_b64 is not None:
        entry["sig"] = sig_b64
        entry["signed_at"] = now
        if sig_kid:
            entry["sig_kid"] = sig_kid
        if sig_source:
            entry["signed_with"] = sig_source
    data["platforms"]["zen_cart"] = entry
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(
        json.dumps(data, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    print(f"  manifest.json updated for zen_cart v{bare_version}")


def publish_artifacts_locally(
    manifest_path: Path,
    zip_path: Path,
    sidecar_path: Path,
    sig_sidecar_path: Path,
) -> None:
    """Copy release zip + sidecars next to manifest.json (monorepo public/plugins/)."""
    plugins_dir = manifest_path.parent
    plugins_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(zip_path, plugins_dir / zip_path.name)
    shutil.copy2(sidecar_path, plugins_dir / sidecar_path.name)
    shutil.copy2(sig_sidecar_path, plugins_dir / sig_sidecar_path.name)
    print(f"  published {zip_path.name} + sidecars -> {plugins_dir}")


def sync_version_history(repo_dir: Path, version: str) -> bool:
    """Update zen-cart/page.tsx VERSION_HISTORY from CHANGELOG.md."""
    if not SYNC_VERSION_HISTORY_SCRIPT.is_file():
        print(f"  WARN: {SYNC_VERSION_HISTORY_SCRIPT} missing; skipping VERSION_HISTORY sync")
        return False
    page = repo_dir / SEEKMODO_VERSION_HISTORY_PAGE_REL
    changelog = REPO_ROOT / "CHANGELOG.md"
    cmd = [
        sys.executable,
        str(SYNC_VERSION_HISTORY_SCRIPT),
        "--platform",
        "zen_cart",
        "--version",
        version.lstrip("v"),
        "--changelog",
        str(changelog),
        "--seekmodo-root",
        str(repo_dir),
        "--page",
        str(page),
    ]
    print("  $ " + " ".join(cmd))
    subprocess.run(cmd, check=True)
    return True


def auto_pr(
    zip_path: Path,
    sidecar_path: Path,
    version: str,
    sha256: str,
    seekmodo_token: str,
    sig_sidecar_path: Path | None = None,
    sig_b64: str | None = None,
    sig_kid: str | None = None,
    sig_source: str | None = None,
) -> None:
    """Clone numinix/seekmodo into a temp dir, drop the zip + sha256
    + updated manifest.json under services/marketing-site/public/
    plugins/, push a branch, and open + auto-merge a PR.

    Requires:
      - git + gh on PATH
      - GH_TOKEN / SEEKMODO_PUBLISH_TOKEN with `repo` scope on
        ``numinix/seekmodo`` (passed as `seekmodo_token`).
    """
    if not seekmodo_token:
        raise SystemExit("ERROR: --auto-pr needs SEEKMODO_PUBLISH_TOKEN env or --token")
    if shutil.which("gh") is None:
        raise SystemExit("ERROR: gh CLI not on PATH; required for --auto-pr.")

    bare_version = version.lstrip("v")
    branch = f"connector/publish-v{bare_version}"
    pr_title = f"connector: publish v{bare_version}"

    with tempfile.TemporaryDirectory(prefix="seekmodo-publish-") as tmpdir:
        tmp = Path(tmpdir)
        clone_url = f"https://x-access-token:{seekmodo_token}@github.com/{SEEKMODO_REPO}.git"
        repo_dir = tmp / "seekmodo"
        _run(["git", "clone", "--depth", "1", "--branch", SEEKMODO_DEFAULT_BRANCH, clone_url, str(repo_dir)])
        # Set committer identity for this clone — the workflow runs
        # without ambient git config.
        _run(["git", "-C", str(repo_dir), "config", "user.email", "release-bot@seekmodo.com"])
        _run(["git", "-C", str(repo_dir), "config", "user.name", "Seekmodo Release Bot"])
        _run(["git", "-C", str(repo_dir), "checkout", "-b", branch])

        plugins_dir = repo_dir / SEEKMODO_PLUGINS_DIR_REL
        plugins_dir.mkdir(parents=True, exist_ok=True)
        target_zip = plugins_dir / zip_path.name
        target_sha = plugins_dir / sidecar_path.name
        shutil.copy2(zip_path, target_zip)
        shutil.copy2(sidecar_path, target_sha)
        add_paths = [
            SEEKMODO_PLUGINS_DIR_REL + "/" + zip_path.name,
            SEEKMODO_PLUGINS_DIR_REL + "/" + sidecar_path.name,
            SEEKMODO_MANIFEST_REL,
        ]
        if sig_sidecar_path is not None and sig_sidecar_path.is_file():
            target_sig = plugins_dir / sig_sidecar_path.name
            shutil.copy2(sig_sidecar_path, target_sig)
            add_paths.append(SEEKMODO_PLUGINS_DIR_REL + "/" + sig_sidecar_path.name)
        manifest_target = repo_dir / SEEKMODO_MANIFEST_REL
        update_manifest_json(
            manifest_target,
            version=version,
            sha256=sha256,
            zip_basename=zip_path.name,
            sig_b64=sig_b64,
            sig_kid=sig_kid,
            sig_source=sig_source,
        )

        sync_version_history(repo_dir, version)
        add_paths.append(SEEKMODO_VERSION_HISTORY_PAGE_REL)

        _run(["git", "-C", str(repo_dir), "add", *add_paths])
        commit_msg = (
            f"connector: publish v{bare_version}\n\n"
            f"Auto-generated by github.com/numinix/seekmodo-zen-cart-connector "
            f"release pipeline on tag v{bare_version}.\n\n"
            f"- Drops the signed zip at "
            f"services/marketing-site/public/plugins/{zip_path.name} "
            f"({zip_path.stat().st_size:,} bytes, sha256 {sha256[:12]}...).\n"
            f"- Updates manifest.json's zen_cart.latest pointer.\n"
            f"- Syncs VERSION_HISTORY on the Zen Cart download page from CHANGELOG.md.\n\n"
            f"The seek-api01 deploy webhook will pick up this push and "
            f"redeploy the marketing site so the new download is "
            f"available at {DOWNLOAD_BASE}/{zip_path.name}."
        )
        _run(["git", "-C", str(repo_dir), "commit", "-m", commit_msg])
        _run(["git", "-C", str(repo_dir), "push", "-u", "origin", branch])

        gh_env = {"GH_TOKEN": seekmodo_token}
        _run(
            ["gh", "pr", "create",
             "--repo", SEEKMODO_REPO,
             "--base", SEEKMODO_DEFAULT_BRANCH,
             "--head", branch,
             "--title", pr_title,
             "--body", commit_msg],
            cwd=repo_dir,
            env=gh_env,
        )
        # Auto-merge: the seekmodo monorepo's main is protected only at
        # signed-tag level; the release-bot has admin merge rights.
        _run(
            ["gh", "pr", "merge",
             "--repo", SEEKMODO_REPO,
             "--merge",
             "--admin",
             "--delete-branch",
             branch],
            cwd=repo_dir,
            env=gh_env,
        )
        print(f"  PR opened + auto-merged into {SEEKMODO_REPO}@{SEEKMODO_DEFAULT_BRANCH}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--bump", choices=["patch", "minor", "major"], default=None,
                    help="Optional: bump the manifest version before zipping.")
    ap.add_argument("--manifest-path", default=None,
                    help="Path of a manifest.json to update with this release's "
                         "metadata. When omitted, no manifest is written.")
    ap.add_argument("--auto-pr", action="store_true",
                    help="After building, open + auto-merge a publish PR into "
                         f"{SEEKMODO_REPO}. Requires SEEKMODO_PUBLISH_TOKEN env.")
    ap.add_argument("--token", default=None,
                    help="Override SEEKMODO_PUBLISH_TOKEN. Mainly for local "
                         "smoke testing — leave empty in CI.")
    ap.add_argument("--skip-docs", action="store_true",
                    help="Emergency only: skip rendering README.html into the zip.")
    ap.add_argument("--skip-key-vendor", action="store_true",
                    help="Do not overwrite admin/release-signing.pub in the plugin tree. "
                         "Use for docs-only repacks at an unchanged version when the "
                         "committed production pubkey must be preserved.")
    args = ap.parse_args()

    print("== Build connector release ==")
    print(f"  plugin root: {PLUGIN_ROOT.relative_to(REPO_ROOT)}")
    print(f"  dist dir:    {DIST_DIR.relative_to(REPO_ROOT)}")
    print()

    version_dir, triple = discover_current_version()
    print(f"-- current version: v{triple[0]}.{triple[1]}.{triple[2]} ({version_dir.relative_to(REPO_ROOT)})")

    if args.bump:
        new_triple = bump(triple, args.bump)
        new_version_str = f"v{new_triple[0]}.{new_triple[1]}.{new_triple[2]}"
        new_dir = PLUGIN_ROOT / new_version_str
        if new_dir.exists():
            print(f"ERROR: target dir {new_dir} already exists; remove it first.", file=sys.stderr)
            return 2
        shutil.copytree(version_dir, new_dir)
        write_manifest_version(new_dir / "manifest.php", new_triple)
        version_dir = new_dir
        version_str = new_version_str
        print(f"  bumped to {version_str}")
    else:
        version_str = f"v{triple[0]}.{triple[1]}.{triple[2]}"

    print()
    print(f"-- resolving release-signing keypair")
    signer, public_raw, sig_source, sig_kid = _load_or_generate_signing_key()
    if args.skip_key_vendor:
        vendored_pub = version_dir / "admin" / "release-signing.pub"
        if not vendored_pub.is_file():
            raise SystemExit(
                f"ERROR: --skip-key-vendor but {vendored_pub} is missing."
            )
        print(f"  skipped key vendor (--skip-key-vendor); using committed {vendored_pub.relative_to(REPO_ROOT)}")
    else:
        # Vendor the matching public key into the per-version plugin
        # tree BEFORE building the zip, so the zip we ship carries the
        # same trust root the in-plugin verifier will use to validate
        # the *next* release. Earlier builds vendored AFTER zipping,
        # which left the shipped zip with whatever pubkey was committed
        # in the source tree (typically a stale dev-ephemeral leftover)
        # — silent bit-rot we paper-fixed for v1.0.18 + the
        # seekmodo-2026-06 rotation. The source tree is also updated so
        # the same content is committed in the PR that ships this
        # version.
        vendored_pub = vendor_public_key(version_dir, public_raw, sig_kid)
        print(f"  vendored public key -> {vendored_pub.relative_to(REPO_ROOT)}")
    print(f"  kid={sig_kid}, source={sig_source}")

    print()
    vendor_sdk(version_dir)

    print()
    print(f"-- zipping {version_dir.relative_to(REPO_ROOT)} -> {version_str}.zip")
    zip_path = build_zip(version_dir, version_str)

    if not args.skip_docs:
        print()
        print("-- connector documentation")
        doc_root = render_connector_docs(version_str)
        try:
            inject_docs_into_zip(zip_path, doc_root)
        finally:
            shutil.rmtree(doc_root, ignore_errors=True)

    print()
    print(f"-- sha256 sidecar")
    sidecar_path, digest = write_sha256_sidecar(zip_path)

    print()
    print(f"-- ed25519 detached signature")
    sig_sidecar_path, sig_b64 = write_detached_signature_for_zip(zip_path, signer)
    print(f"  wrote {sig_sidecar_path.relative_to(REPO_ROOT)} (key={sig_source}, kid={sig_kid})")

    if args.manifest_path:
        print()
        print(f"-- manifest.json @ {args.manifest_path}")
        update_manifest_json(
            Path(args.manifest_path),
            version=version_str,
            sha256=digest,
            zip_basename=zip_path.name,
            sig_b64=sig_b64,
            sig_kid=sig_kid,
            sig_source=sig_source,
        )
        publish_artifacts_locally(
            Path(args.manifest_path),
            zip_path,
            sidecar_path,
            sig_sidecar_path,
        )

    if args.auto_pr:
        print()
        print(f"-- auto-PR into {SEEKMODO_REPO}")
        token = args.token or os.environ.get("SEEKMODO_PUBLISH_TOKEN", "")
        auto_pr(
            zip_path=zip_path,
            sidecar_path=sidecar_path,
            version=version_str,
            sha256=digest,
            seekmodo_token=token,
            sig_sidecar_path=sig_sidecar_path,
            sig_b64=sig_b64,
            sig_kid=sig_kid,
            sig_source=sig_source,
        )

    print()
    print("Release ready.")
    print(f"  zip:        {zip_path}")
    print(f"  sha256:     {digest}")
    print(f"  signature:  {sig_b64[:16]}... (kid={sig_kid}, source={sig_source})")
    return 0


if __name__ == "__main__":
    sys.exit(main())

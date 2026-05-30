"""Build a Zen Cart connector release zip from the plugin tree.

Repo: github.com/numinix/seekmodo-zen-cart-connector

Produces a Zen Cart-compatible plugin zip plus a SHA-256 sidecar and
(optionally) a ``manifest.json`` entry suitable for publishing to the
seekmodo monorepo's ``services/marketing-site/public/plugins/``
directory. Designed to be driven by ``.github/workflows/release.yml``
on a tag push, but also runs locally.

The version is read from the highest-numbered ``v*/manifest.php``'s
``pluginVersion`` field.

Run from the connector repo root:

    python tools/build_release.py
    python tools/build_release.py --bump patch
    python tools/build_release.py --manifest-path manifest.json
    python tools/build_release.py --auto-pr   # opens a PR into seekmodo
"""
from __future__ import annotations

import argparse
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

REPO_ROOT = Path(__file__).resolve().parent.parent
PLUGIN_ROOT = REPO_ROOT / "zc_plugins" / "Seekmodo"
MANIFEST_RE = re.compile(r"'pluginVersion'\s*=>\s*'v(\d+)\.(\d+)\.(\d+)'")
DIST_DIR = REPO_ROOT / "dist"

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


def _run(cmd: list[str], cwd: Path | None = None, check: bool = True, env: dict | None = None) -> subprocess.CompletedProcess:
    """Thin subprocess.run wrapper that prints the command first."""
    print("  $ " + " ".join(cmd))
    return subprocess.run(
        cmd,
        cwd=str(cwd) if cwd else None,
        check=check,
        env={**os.environ, **(env or {})},
        capture_output=True,
        text=True,
    )


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


def update_manifest_json(
    manifest_path: Path,
    version: str,
    sha256: str,
    zip_basename: str,
) -> None:
    """Update or create a `manifest.json` describing the latest release.

    Schema (minimum):

        {
          "platforms": {
            "zen_cart": {
              "latest": "1.0.5",
              "url": "https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.5.zip",
              "sha256": "...",
              "released_at": "2026-...",
              "release_notes_url": "https://seekmodo.com/plugins/zen-cart#changelog",
              "min_compatible_gateway": "0.1.0"
            }
          }
        }

    Sprint 4 will extend this with a `versions` history list; for now
    the `latest` pointer is sufficient for one-tenant download.
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
    entry = {
        "latest": bare_version,
        "url": f"{DOWNLOAD_BASE}/{zip_basename}",
        "sha256": sha256,
        "released_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
        "release_notes_url": "https://seekmodo.com/plugins/zen-cart#changelog",
        "min_compatible_gateway": MIN_COMPATIBLE_GATEWAY,
    }
    data["platforms"]["zen_cart"] = entry
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(
        json.dumps(data, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    print(f"  manifest.json updated for zen_cart v{bare_version}")


def auto_pr(
    zip_path: Path,
    sidecar_path: Path,
    version: str,
    sha256: str,
    seekmodo_token: str,
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
        manifest_target = repo_dir / SEEKMODO_MANIFEST_REL
        update_manifest_json(
            manifest_target,
            version=version,
            sha256=sha256,
            zip_basename=zip_path.name,
        )

        _run(["git", "-C", str(repo_dir), "add",
              SEEKMODO_PLUGINS_DIR_REL + "/" + zip_path.name,
              SEEKMODO_PLUGINS_DIR_REL + "/" + sidecar_path.name,
              SEEKMODO_MANIFEST_REL])
        commit_msg = (
            f"connector: publish v{bare_version}\n\n"
            f"Auto-generated by github.com/numinix/seekmodo-zen-cart-connector "
            f"release pipeline on tag v{bare_version}.\n\n"
            f"- Drops the signed zip at "
            f"services/marketing-site/public/plugins/{zip_path.name} "
            f"({zip_path.stat().st_size:,} bytes, sha256 {sha256[:12]}...).\n"
            f"- Updates manifest.json's zen_cart.latest pointer.\n\n"
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
    print(f"-- zipping {version_dir.relative_to(REPO_ROOT)} -> {version_str}.zip")
    zip_path = build_zip(version_dir, version_str)

    print()
    print(f"-- sha256 sidecar")
    sidecar_path, digest = write_sha256_sidecar(zip_path)

    if args.manifest_path:
        print()
        print(f"-- manifest.json @ {args.manifest_path}")
        update_manifest_json(
            Path(args.manifest_path),
            version=version_str,
            sha256=digest,
            zip_basename=zip_path.name,
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
        )

    print()
    print("Release ready.")
    print(f"  zip:    {zip_path}")
    print(f"  sha256: {digest}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

"""Publish a Zen Cart connector release to Numinix.com via the /mcp/ API.

Numinix.com packages free plugins through Numinix\\PluginRelease\\Releaser
(clone repo → tag → zip → free_download_manager). This is separate from the
signed seekmodo.com zip — run *after* the connector tag is pushed to GitHub
and seekmodo.com publish is complete.

When the product has products.zencart_com_plugin_id set, Releaser queues a
zen-cart.com update. This script then drains that queue via
numinix.com-local/scripts/publish_zencart_com_release.py (SeleniumBase UC)
unless --skip-zencart-com is passed.

Usage (from connector repo root, after git push origin vX.Y.Z):

    python tools/publish_numinix_release.py --tag v1.2.9

Environment:
    NUMINIX_MCP_BEARER — Bearer token (see numinix.com-local/config/
    server-access.local.json → mcp_plugin_release.bearer_token)

Optional:
    --products-id 2044   (Seekmodo for Zen Cart on www.numinix.com)
    --endpoint https://www.numinix.com/mcp/
    --description "..."  Release notes for the FDM row (default: top CHANGELOG entry)
    --skip-zencart-com   Do not drain the zen-cart.com marketplace queue
    --dry-run            Print payload without calling MCP
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_PRODUCTS_ID = 2044
DEFAULT_ENDPOINT = "https://www.numinix.com/mcp/"
CONFIG_CANDIDATES = [
    Path(os.environ.get("NUMINIX_LOCAL_CONFIG", "")),
    Path("C:/Users/Jeff Lew/Repositories/Clients/NX/numinix.com-local/config/server-access.local.json"),
    Path.home() / "Repositories/Clients/NX/numinix.com-local/config/server-access.local.json",
]
SUBMITTER_CANDIDATES = [
    Path(os.environ.get("ZENCART_COM_SUBMITTER", "")),
    Path("C:/Users/Jeff Lew/Repositories/Clients/NX/numinix.com-local/scripts/publish_zencart_com_release.py"),
    Path.home() / "Repositories/Clients/NX/numinix.com-local/scripts/publish_zencart_com_release.py",
]


def _load_bearer() -> str:
    token = os.environ.get("NUMINIX_MCP_BEARER", "").strip()
    if token:
        return token
    for path in CONFIG_CANDIDATES:
        if not path or not path.is_file():
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            token = (data.get("mcp_plugin_release") or {}).get("bearer_token", "")
            if token:
                return token.strip()
        except Exception:
            continue
    raise SystemExit(
        "NUMINIX_MCP_BEARER not set and server-access.local.json not found."
    )


def _normalize_tag(raw: str) -> str:
    raw = raw.strip()
    if raw.startswith("v"):
        raw = raw[1:]
    if not re.fullmatch(r"\d+\.\d+\.\d+", raw):
        raise SystemExit(f"Invalid tag {raw!r}; expected vX.Y.Z or X.Y.Z")
    return raw


def _default_description(tag: str) -> str:
    changelog = REPO_ROOT / "CHANGELOG.md"
    if not changelog.is_file():
        return f"Release {tag}"
    text = changelog.read_text(encoding="utf-8")
    bare = f"v{tag}"
    for line in text.splitlines():
        if line.startswith(f"## {bare}") or line.startswith(f"## v{tag}"):
            rest = text.split(line, 1)[1].split("##", 1)[0].strip()
            for chunk in rest.splitlines():
                chunk = chunk.strip()
                if chunk and not chunk.startswith("#"):
                    return chunk.lstrip("- ").strip()
    return f"Release {tag}"


def _mcp_call(endpoint: str, bearer: str, tool: str, arguments: dict) -> dict:
    payload = {
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/call",
        "params": {"name": tool, "arguments": arguments},
    }
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        endpoint,
        data=body,
        headers={
            "Authorization": f"Bearer {bearer}",
            "Content-Type": "application/json",
            "User-Agent": "NuminixReleaseBot/1.0",
            "Accept": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise SystemExit(f"MCP HTTP {exc.code}: {detail}") from exc


def _find_submitter() -> Path | None:
    for path in SUBMITTER_CANDIDATES:
        if path and path.is_file():
            return path
    return None


def _drain_zencart_com(endpoint: str, tag: str, description: str) -> None:
    submitter = _find_submitter()
    if submitter is None:
        print(
            "WARN: publish_zencart_com_release.py not found; "
            "leaving marketplace queue pending.",
            file=sys.stderr,
        )
        return
    secrets = Path(r"C:\Users\Jeff Lew\Repositories\Clients\NX\secrets\zencart.com.txt")
    if not secrets.is_file():
        print(
            "WARN: NX/secrets/zencart.com.txt missing; "
            "leaving marketplace queue pending.",
            file=sys.stderr,
        )
        return
    cmd = [
        sys.executable,
        str(submitter),
        "--drain-numinix-queue",
        "--endpoint",
        endpoint,
    ]
    print("== Drain zen-cart.com marketplace queue ==")
    print(" ", " ".join(cmd))
    try:
        proc = subprocess.run(cmd, check=False)
    except FileNotFoundError as exc:
        print(f"WARN: could not run submitter: {exc}", file=sys.stderr)
        return
    if proc.returncode != 0:
        print(
            f"WARN: zen-cart.com submit exited {proc.returncode}; "
            "queue row may be marked failed — check MCP marketplace_queue_list.",
            file=sys.stderr,
        )
    else:
        print("zen-cart.com marketplace drain finished.")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--tag", required=True, help="Release tag (vX.Y.Z or X.Y.Z)")
    ap.add_argument("--products-id", type=int, default=DEFAULT_PRODUCTS_ID)
    ap.add_argument("--endpoint", default=DEFAULT_ENDPOINT)
    ap.add_argument("--description", default=None)
    ap.add_argument("--skip-zencart-com", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    tag = _normalize_tag(args.tag)
    description = args.description or _default_description(tag)
    bearer = _load_bearer()

    arguments = {
        "products_id": args.products_id,
        "tag": tag,
        "description": description,
    }

    print("== Publish to Numinix.com ==")
    print(f"  endpoint:    {args.endpoint}")
    print(f"  products_id: {args.products_id}")
    print(f"  tag:         {tag}")
    print(f"  description: {description[:120]}{'...' if len(description) > 120 else ''}")

    if args.dry_run:
        print(json.dumps(arguments, indent=2))
        return 0

    result = _mcp_call(args.endpoint, bearer, "release_plugin", arguments)
    if result.get("error"):
        print(json.dumps(result, indent=2), file=sys.stderr)
        return 1
    inner = result.get("result") or {}
    if inner.get("isError"):
        print(json.dumps(inner, indent=2), file=sys.stderr)
        return 1
    structured = inner.get("structuredContent") or {}
    print(json.dumps(structured, indent=2))
    print(
        f"\nLive: https://www.numinix.com/index.php?main_page=download_product_info&products_id={args.products_id}"
    )

    if args.skip_zencart_com:
        print("Skipping zen-cart.com drain (--skip-zencart-com).")
        return 0

    if structured.get("marketplace_queued"):
        _drain_zencart_com(args.endpoint, tag, description)
    else:
        reason = structured.get("marketplace_reason") or "not_queued"
        print(
            f"zen-cart.com auto-submit not queued ({reason}). "
            "Set products.zencart_com_plugin_id after a manual first listing."
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

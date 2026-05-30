# Installing the Seekmodo Zen Cart connector

This is the operator-facing install guide. It assumes:

- You have a Seekmodo subscription at <https://seekmodo.com>.
- Your storefront is on **Zen Cart 1.5.8+**.
- You have admin access to your Zen Cart store.

If you're upgrading from an existing install, skip to
[`UPGRADE.md`](UPGRADE.md). If something goes sideways, see
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

---

## 1. Download the plugin

Always download the **signed** zip from `seekmodo.com/plugins`:

```bash
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.4.zip
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.4.zip.sha256
sha256sum -c seekmodo-zen-cart-v1.0.4.zip.sha256
# expected: seekmodo-zen-cart-v1.0.4.zip: OK
```

The latest version pointer lives at
<https://seekmodo.com/plugins/manifest.json> if you're scripting the
download.

> **Don't grab the zip from a third-party mirror.** Numinix.com
> publishes a mirror with a separate signing chain; any other source
> is unverified.

## 2. Install through Zen Cart's Plugin Manager

1. Log in to your Zen Cart admin (`/admin` or your renamed admin path).
2. Go to **Tools → Plugin Manager**.
3. Click **Upload New Plugin** and select the downloaded zip.
4. Find the row labelled **Seekmodo** in the listing and click
   **Install**.
5. Approve the install confirmation. The Scripted Installer will:
   - Create the `Seekmodo Search` configuration group.
   - Seed 13 `NUMINIX_SEEKMODO_*` configuration rows with safe
     defaults (`MODE=off`, `AUTO_PROMOTE=true`, `TIMEOUT_MS=250`,
     etc.).
   - Hide the configuration group from the admin sidebar by default
     — once paired, settings live on `admin.seekmodo.com`.

After install, the row should read **Seekmodo v1.0.4 — Installed**.

## 3. Pair the storefront with your Seekmodo tenant

This is the one-click flow that round-trips your tenant ID and HMAC
secret without any copy-paste.

1. In Zen Cart admin, go to **Tools → Connect to Seekmodo** (added by
   the plugin's `admin/numinix_seekmodo_connect.php` page).
2. Click **Connect**. The page redirects you to
   `https://seekmodo.com/connect?install_token=...&callback=...` with
   a one-time token.
3. Sign in to seekmodo.com (if you're not already), pick the tenant to
   bind to, and approve.
4. Seekmodo redirects you back to your storefront's pair-callback URL
   carrying a signed payload that the plugin verifies against the
   public JWKS at `https://seekmodo.com/.well-known/jwks.json`.
5. On success, the plugin populates `NUMINIX_SEEKMODO_TENANT_ID` and
   `NUMINIX_SEEKMODO_SHARED_SECRET` in your Zen Cart database. The
   page shows a green "Paired" banner.

If anything fails, you'll see the cause inline — the most common is a
clock skew of more than 5 minutes between your Zen Cart host and the
gateway. NTP is your friend.

> Going manual? You can still set the two constants by hand from
> **Admin → Configuration → Seekmodo Search** if you've made the
> group visible. The pairing flow is preferred because it avoids
> exposing the secret in your terminal history.

## 4. Flip from `off` to `active`

Open <https://admin.seekmodo.com>, find your tenant, and switch
`Mode` from `Disabled` to `Learning` or `Active`. The connector polls
`tenant.snapshot` every 5 minutes (more often when you've just
paired); within that window your storefront starts using Seekmodo.

| seekmodo.com label | Internal mode | What it does |
|---|---|---|
| Disabled | `off` | Bypass — direct Typesense + local row. |
| Learning | `shadow` | Calls the gateway for diff/observation; native result still served. |
| Active (recommended) | `active` | Auto-promotes shadow → enforce based on observed gateway health; auto-demotes on sustained failures. |

On a brand-new install, `Active` self-promotes from `shadow` to
`enforce` after about 50 successful storefront searches with the
gateway healthy. You can watch the promotion in
`<docroot>/logs/numinix_seekmodo.log` if `NUMINIX_SEEKMODO_DEBUG=true`.

## 5. Verify

Three checks. Two manual, one scripted.

### 5a. Plugin Manager smoke

**Admin → Plugin Manager**: row reads `Seekmodo v1.0.4 — Installed`.

### 5b. Storefront smoke

Search for a known product on the storefront. Tail
`<docroot>/logs/numinix_seekmodo.log` while running the search:

```
2026-05-30T18:14:07Z  search backend=gateway  mode=enforce  ms=84  hits=12  status=200
```

If the line says `backend=native` or doesn't appear at all, see
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

### 5c. Gateway-side telemetry

Sign in to <https://admin.seekmodo.com>, open **Analytics → Top
queries**, and confirm your test search appears. Click events arrive
within ~5 seconds.

## 6. Recurring maintenance

There isn't much. The plugin pulls policy from the gateway in real
time, the indexer runs from your existing Zen Cart cron, and the
circuit breaker handles transient gateway issues automatically.

When a new release ships, you'll receive an email from
`releases@seekmodo.com`. Re-run the **Upload New Plugin** flow with
the new zip — the Scripted Installer detects the existing version and
upgrades cleanly, preserving config rows. Detail in
[`UPGRADE.md`](UPGRADE.md).

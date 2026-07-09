# Installing the Seekmodo Zen Cart connector

This is the operator-facing install guide. It assumes:

- You have a Seekmodo subscription at <https://seekmodo.com>.
- Your storefront is on **Zen Cart 1.5.7+** (1.5.7 supported since
  connector **v1.3.19**; see [`PLATFORM_NOTES.md`](PLATFORM_NOTES.md)).
- You have admin access to your Zen Cart store.

If you're upgrading from an existing install, skip to
[`UPGRADE.md`](UPGRADE.md). If something goes sideways, see
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

> **Download size sanity check:** the signed release zip from
> `seekmodo.com/plugins` is typically **~300–350 KB** and expands to
> **~1 MB** of plugin files under `zc_plugins/Seekmodo/v<X.Y.Z>/`.
> If you see **tens of megabytes** after unzip, you likely extracted
> into your whole storefront tree or grabbed the wrong archive.

---

## 0. Recommended PHP extensions

The connector self-checks the PHP environment and surfaces the
result on **Tools → Connect to Seekmodo** (under *Diagnostics*),
once paired. None of these are install-time gates — the plugin
loads on PHP 7.4+ regardless — but each one matters for steady-state
behaviour. Skim this list before install so the diagnostics panel is
boring on day 1.

| Extension | Required? | Why it matters |
|---|---|---|
| `sodium` | **Required** | Pairing uses ed25519 to verify the Seekmodo callback. Missing sodium fails pairing outright. |
| `apcu` (with `apc.enabled=1`) | Strongly recommended | The connector caches the gateway snapshot for 5 minutes in APCu. Without it, every storefront request re-pulls the snapshot — admin pages lag and you'll see "gateway unreachable" flicker on the connector admin page even when the gateway is fine. **This was the production-stability cliff that drove §0.6 P1-4** — before v1.0.19 the connector would lock up admin pages on hosts without APCu. |
| `opcache` (`opcache.enable=1`) | Strongly recommended | Standard PHP perf, not specific to Seekmodo. PHP reparses every request without it; the connector ships ~2k lines of code that don't need re-tokenizing on every page render. |
| `curl` | Required | Connector uses curl for outbound calls to `mcp.seekmodo.com`. |
| `openssl` | Required | TLS for the curl calls; also belt-and-braces for `random_bytes()` on hosts where libsodium's PRNG isn't available. |
| `mysqli` | Required | Zen Cart's standard DB driver. Already installed on any Zen Cart 1.5.8+ host. |
| `intl` (provides IDN) | Optional | Used to canonicalize internationalized storefront hostnames before sending them to the gateway's locked-domain check. Pure-ASCII shops don't need it. |

Quick check from the host:

```bash
php -v                                # need >= 7.4
php -m | grep -E 'sodium|apcu|opcache|curl|openssl|mysqli|intl'
```

If `apcu` is missing on **cPanel / EasyApache 4**:

```bash
yum install -y ea-php81-php-pecl-apcu     # adjust ea-phpXX to your runtime
```

If `apcu` is missing on **Debian / Ubuntu**:

```bash
apt-get install -y php8.1-apcu            # adjust 8.1 to your runtime
phpenmod apcu
systemctl restart php8.1-fpm apache2      # or nginx
```

If you're on **managed hosting** without shell access, paste this
ticket into your provider's support form (replace the version):

> Subject: please enable the apcu PHP extension
>
> Hello — our Zen Cart storefront uses the Seekmodo connector,
> which expects APCu to be loaded with `apc.enabled=1` (and
> `apc.enable_cli=1` if cron tasks call PHP directly). Could
> you install the `php-pecl-apcu` package for our PHP runtime
> and confirm `php -m | grep apcu` shows the extension loaded?
> Thanks.

After install, refresh **Tools → Connect to Seekmodo**; the APCu
row in the *Diagnostics* table should flip from yellow to green.

The matching operator-side surface lives at **admin.seekmodo.com →
Settings**, where the same checks render in a *Connector
environment* card (v1.0.19+ pushes the env data up on every FSM
update).

## 1. Download the plugin

Always download the **signed** zip from `seekmodo.com/plugins`:

```bash
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.5.zip
curl -fLO https://seekmodo.com/plugins/seekmodo-zen-cart-v1.0.5.zip.sha256
sha256sum -c seekmodo-zen-cart-v1.0.5.zip.sha256
# expected: seekmodo-zen-cart-v1.0.5.zip: OK
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

After install, the row should read **Seekmodo v1.0.5 — Installed**.

> **Upload alone is not enough.** Zen Cart stages the zip when you
> click **Upload New Plugin**, but the catalog-root callback shims
> (see §2a) are not live until you click **Install** (first time) or
> **Update / Upgrade** (existing row). A Plugin Manager message of
> "Successful" on upload does **not** mean pairing will work yet.

## 2a. Zen Cart 1.5.7, subdirectory catalogs, and file-only installs

These stores need one extra check that 1.5.8+ installs often get for
free from Plugin Manager.

### When this section applies

- **Zen Cart 1.5.7** (manifest `v157`; use connector **v1.3.19+**).
- You copied `zc_plugins/Seekmodo/` by **FTP, git, or rsync** instead
  of using Plugin Manager end-to-end.
- Your catalog lives in a **subdirectory** (e.g.
  `https://www.example.com/shop/`). That layout is fine — you do
  **not** put `/shop/` in the Seekmodo store-domain setting; pairing
  uses Zen Cart's `HTTPS_CATALOG_SERVER` + `DIR_WS_CATALOG`
  automatically.

### What Plugin Manager must deploy

Pairing posts credentials to a **catalog-root** URL:

```text
https://<your-storefront-host><DIR_WS_CATALOG>numinix_seekmodo_pair_callback.php
```

For a shop at `https://www.example.com/shop/`, that is:

```text
https://www.example.com/shop/numinix_seekmodo_pair_callback.php
```

Plugin Manager **Install** or **Upgrade** copies these top-level
shims from the plugin tree into your catalog root (same directory as
`index.php`):

| Shim (catalog root) | Purpose |
|---|---|
| `numinix_seekmodo_pair_callback.php` | **Required for Connect to Seekmodo** |
| `numinix_seekmodo_suggest.php` | Typeahead / suggest proxy |
| `numinix_seekmodo_push_catalog.php` | Initial + scheduled indexing |
| `numinix_seekmodo_click.php` | Click telemetry |
| `numinix_seekmodo_recommend.php` | Recommendation widgets |
| `numinix_seekmodo_index_delta.php` | Delta indexing |
| `numinix_seekmodo_forget_me.php` | Shopper forget-me |
| `numinix_seekmodo_reconcile_cron.php` | Cron reconciliation |

The observer / library code under
`zc_plugins/Seekmodo/v<X.Y.Z>/catalog/includes/` loads via Zen
Cart's plugin auto-loader once the plugin row is **Installed** — but
**pairing will silently fail** if the callback shim above is missing
from the catalog root.

### Standard recovery (recommended)

1. Download the latest signed zip from `seekmodo.com/plugins`.
2. **Tools → Plugin Manager → Upload New Plugin** (even if files are
   already on disk).
3. On the **Seekmodo** row, click **Install** (new) or **Update /
   Upgrade** (existing).
4. Run the verification curl in §2a below — it must return **JSON**,
   not your storefront homepage HTML.
5. Go to **Tools → Connect to Seekmodo** and pair again.

On **Zen Cart 1.5.7**, prefer this Plugin Manager path over **Tools →
Seekmodo Updates → Apply update** alone — the in-plugin auto-updater
refreshes the versioned plugin tree but may not redeploy catalog-root
shims on older cores.

### Manual recovery (FTP / SSH)

If Plugin Manager Install/Upgrade is unavailable or returns HTTP 500
on 1.5.7, copy the eight `numinix_seekmodo_*.php` files from:

```text
zc_plugins/Seekmodo/v<X.Y.Z>/catalog/
```

into your **catalog root** (e.g. `/shop/` on the server). Then ensure
the plugin row is active:

```sql
SELECT unique_key, version, status FROM plugin_control WHERE unique_key = 'Seekmodo';
-- expect status = 1 and version = v<X.Y.Z>
```

### Verify catalog shims before pairing

From any machine that can reach your storefront:

```bash
curl -s -X POST "https://www.example.com/shop/numinix_seekmodo_pair_callback.php" \
  -H "Content-Type: application/json" -d "{}"
```

| Response | Meaning |
|---|---|
| `{"ok":false,"error":"empty body"}` (or similar JSON) | Shim is live — proceed to §3 |
| Full storefront HTML (homepage) | Shim missing or not routed — redo §2a |
| HTTP 404 | Wrong catalog path or file not copied |

After pairing succeeds, confirm credentials landed:

```sql
SELECT configuration_key, configuration_value
FROM   configuration
WHERE  configuration_key IN (
  'NUMINIX_SEEKMODO_TENANT_ID',
  'NUMINIX_SEEKMODO_SHARED_SECRET'
);
```

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

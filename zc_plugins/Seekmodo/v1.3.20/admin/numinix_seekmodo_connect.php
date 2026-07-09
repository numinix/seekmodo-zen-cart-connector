<?php
/**
 * "Connect to Seekmodo" admin page — read-only snapshot panel.
 *
 * Settings (mode, auto-promote, timeout, index-batch, debug) are
 * managed on https://admin.seekmodo.com/settings; this page exists
 * only to:
 *
 *   1. Show the current pairing state (connected vs not paired).
 *   2. Mirror the gateway snapshot the connector last pulled
 *      (mode, auto_state, last 5 transitions) so an operator can
 *      sanity-check the FSM without leaving Zen Cart admin.
 *   3. Expose a single "Re-pair" button that mints a fresh install
 *      token — identical to the M5 flow.
 *
 * No inline form for editing config rows lives here anymore. Hidden
 * by ScriptedInstaller::hideGroupFromAdmin() means the rows behind
 * Configuration → Seekmodo Search aren't user-visible either.
 */

require 'includes/application_top.php';

use Numinix\Seekmodo\EnvProbe;
use Numinix\Seekmodo\Pairing;
use Numinix\Seekmodo\RemoteConfig;
use Numinix\Seekmodo\UpdateApplier;
use Numinix\Seekmodo\UpdateClient;

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

function _seekmodo_check_csrf(): bool
{
    if (!empty($_POST['securityToken'])) {
        return isset($_SESSION['securityToken']) && hash_equals($_SESSION['securityToken'], $_POST['securityToken']);
    }
    return true;
}

$tenantId = defined('NUMINIX_SEEKMODO_TENANT_ID') ? NUMINIX_SEEKMODO_TENANT_ID : '';
$isPaired = (bool)preg_match('~^[a-z0-9][a-z0-9_\-]{1,63}$~', (string)$tenantId);

$subState = 'unknown';
if (function_exists('numinix_seekmodo_subscription_state')) {
    $subState = numinix_seekmodo_subscription_state();
} elseif (class_exists('Numinix\\Seekmodo\\Client')) {
    $subState = \Numinix\Seekmodo\Client::readSubscriptionState();
}
$subscriptionCancelled = $subState === 'cancelled';

$gatewayBase = defined('NUMINIX_SEEKMODO_URL') ? NUMINIX_SEEKMODO_URL : 'https://mcp.seekmodo.com';
$marketingBase = preg_replace('~^https?://(?:mcp\.|admin\.)?~i', 'https://', rtrim($gatewayBase, '/'));
$marketingBase = preg_replace('~/v1.*$~', '', (string)$marketingBase);

$messages = [];

$pluginVersionDir = realpath(__DIR__ . '/../');
if ($pluginVersionDir === false) {
    $pluginVersionDir = dirname(__DIR__);
}
$currentVersion = 'unknown';
$manifestPhpPath = $pluginVersionDir . DIRECTORY_SEPARATOR . 'manifest.php';
if (is_file($manifestPhpPath)) {
    $localManifest = include $manifestPhpPath;
    if (is_array($localManifest) && isset($localManifest['pluginVersion'])) {
        $currentVersion = (string)$localManifest['pluginVersion'];
    }
}

$updateClient = class_exists(UpdateClient::class) ? UpdateClient::fromRunningPlugin() : null;
$updateEntry = null;
$latestVersion = '';
$updateAvailable = false;
if ($updateClient !== null) {
    $envelope = $updateClient->pullManifest();
    if ($envelope !== null && isset($envelope['entry']) && is_array($envelope['entry'])) {
        $updateEntry = $envelope['entry'];
        $latestVersion = isset($updateEntry['latest']) ? 'v' . ltrim((string)$updateEntry['latest'], 'v') : '';
        if ($latestVersion !== '') {
            $cmp = $updateClient->compareVersions($currentVersion, $latestVersion);
            $updateAvailable = ($cmp < 0);
        }
    }
}

if ($action === 'apply_update' && _seekmodo_check_csrf() && $updateAvailable && $updateEntry !== null && class_exists(UpdateApplier::class)) {
    $applier = UpdateApplier::fromRunningPlugin();
    $result = $applier->apply($updateEntry);
    if ($result['ok']) {
        $msg = 'Connector upgraded to ' . $latestVersion . '.';
        if (isset($result['git_sync']) && is_array($result['git_sync'])) {
            $gs = $result['git_sync'];
            if (!empty($gs['skipped'])) {
                $msg .= ' Git auto-sync skipped (no tooling on this host).';
            } elseif (!empty($gs['ok'])) {
                $msg .= ' Changes queued for git auto-sync.';
            } elseif (!empty($gs['error'])) {
                $msg .= ' Git auto-sync failed: ' . $gs['error'];
            }
        }
        $messages[] = ['type' => 'success', 'text' => $msg];
        $currentVersion = $latestVersion;
        $updateAvailable = false;
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Update failed: ' . ($result['error'] ?? 'unknown error')];
    }
}

// Preflight check: PHP sodium extension is required for JWT
// verification in Pairing::verify_pair_callback(). Surface the
// missing-extension state as a banner BEFORE the merchant clicks
// Connect, instead of letting them get to the seekmodo.com page,
// click Confirm and pair, and only THEN see the error. v1.0.8
// regression — sodium ships with PHP 7.2+ but cPanel/EasyApache
// builds intentionally omit it; the package must be installed
// per-major-minor (ea-php81-php-sodium etc).
$sodiumOk = function_exists('sodium_crypto_sign_verify_detached');

// v1.0.19 — environment probe drives the APCu warning banner and the
// Diagnostics panel below the snapshot. Cheap (no I/O), so it's safe
// to call on every render. Falls back to a defensive empty map if
// the EnvProbe class isn't loadable (e.g. during a partial upgrade
// where the new file hasn't been deployed yet).
$env = class_exists(EnvProbe::class) ? EnvProbe::current() : [];
$apcuOk = !empty($env['apcu_loaded']);

if ($action === 'pair' && _seekmodo_check_csrf()) {
    if (!$sodiumOk) {
        $messages[] = ['type' => 'error', 'text' =>
            'Cannot start pairing: PHP sodium extension is missing on this server. '
            . 'See the warning below for the one-line install command.'
        ];
    } else {
    $catalogBase = (defined('HTTPS_CATALOG_SERVER') && HTTPS_CATALOG_SERVER)
        ? HTTPS_CATALOG_SERVER : (defined('HTTP_CATALOG_SERVER') ? HTTP_CATALOG_SERVER : '');
    $catalogPath = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
    $callbackUrl = rtrim((string)$catalogBase, '/') . $catalogPath . 'numinix_seekmodo_pair_callback.php';

        try {
            $url = Pairing::mint_install_token($marketingBase, $callbackUrl);
            zen_redirect($url);
        } catch (\Throwable $e) {
            $messages[] = ['type' => 'error', 'text' => 'Failed to mint install token: ' . $e->getMessage()];
        }
    }
}

if ($action === 'refresh' && _seekmodo_check_csrf() && $isPaired) {
    if (class_exists(RemoteConfig::class)) {
        $rc = RemoteConfig::fromConfiguration();
        if ($rc !== null) {
            $rc->invalidate();
            $rc->pull();
            $messages[] = ['type' => 'success', 'text' => 'Snapshot refreshed from gateway.'];
        }
    }
}

// Pull the latest snapshot for display. APCu-cached so this is cheap
// even on every page render.
$snapshot = null;
if ($isPaired && class_exists(RemoteConfig::class)) {
    $rc = RemoteConfig::fromConfiguration();
    if ($rc !== null) {
        $snapshot = $rc->pull();
    }
}

// Local FSM state — best-effort fallback when the gateway snapshot
// isn't reachable, so the page still tells the operator something
// useful.
$autoState = defined('NUMINIX_SEEKMODO_AUTO_STATE') ? NUMINIX_SEEKMODO_AUTO_STATE : '';
$autoStateSince = defined('NUMINIX_SEEKMODO_AUTO_STATE_SINCE') ? NUMINIX_SEEKMODO_AUTO_STATE_SINCE : '';
$mode = defined('NUMINIX_SEEKMODO_MODE') ? NUMINIX_SEEKMODO_MODE : 'off';
$historyRaw = defined('NUMINIX_SEEKMODO_AUTO_HISTORY') ? NUMINIX_SEEKMODO_AUTO_HISTORY : '[]';
$history = json_decode((string)$historyRaw, true);
if (!is_array($history)) {
    $history = [];
}

if (is_array($snapshot)) {
    $mode = (string)($snapshot['mode'] ?? $mode);
    $autoState = (string)($snapshot['auto_state'] ?? $autoState);
    $autoStateSince = (string)($snapshot['auto_state_since'] ?? $autoStateSince);
    if (isset($snapshot['auto_history']) && is_array($snapshot['auto_history'])) {
        $history = $snapshot['auto_history'];
    }
}

$lastTransitions = array_slice($history, -5);

require DIR_WS_INCLUDES . 'admin_html_head.php';
?>
<!doctype html>
<html <?= HTML_PARAMS ?>>
<head>
<meta charset="<?= CHARSET ?>">
<title><?= TITLE ?> · Seekmodo Connect</title>
<link rel="stylesheet" href="includes/stylesheet.css">
<style>
.seekmodo-card{max-width:720px;margin:32px auto;padding:24px;border:1px solid #ddd;border-radius:8px;background:#fff;}
.seekmodo-card h1{margin:0 0 8px 0;font-size:22px;}
.seekmodo-card h2{margin:24px 0 8px 0;font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:#666;}
.seekmodo-card p{color:#444;line-height:1.5;}
.seekmodo-card .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;}
.seekmodo-card .badge-paired{background:#e6f7ec;color:#127436;}
.seekmodo-card .badge-unpaired{background:#fff4cc;color:#7a5b00;}
.seekmodo-card .badge-mode{background:#eef2ff;color:#1f4ed8;}
.seekmodo-card .badge-state{background:#fef3c7;color:#7a5b00;}
.seekmodo-card .badge-state-active{background:#dcfce7;color:#127436;}
.seekmodo-card .badge-stale{background:#fde2e2;color:#7a1a1a;}
.seekmodo-card .btn{display:inline-block;background:#1f4ed8;color:#fff;padding:10px 18px;border:0;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;}
.seekmodo-card .btn-secondary{background:#fff;color:#1f4ed8;border:1px solid #1f4ed8;margin-left:8px;}
.seekmodo-card .msg{padding:10px 14px;border-radius:6px;margin:12px 0;font-size:13px;}
.seekmodo-card .msg-error{background:#fde2e2;color:#7a1a1a;}
.seekmodo-card .msg-success{background:#dcfce7;color:#166534;}
.seekmodo-card .msg-warn{background:#fef3c7;color:#7a5b00;border-left:4px solid #d4a017;}
.seekmodo-card .msg-warn strong{color:#5a4400;}
.seekmodo-card .msg-warn details{margin-top:8px;}
.seekmodo-card .msg-warn details summary{cursor:pointer;font-weight:600;}
.seekmodo-card .msg-warn pre{background:#fffbe6;border:1px solid #f0deb0;padding:8px 10px;border-radius:4px;font-size:12px;white-space:pre-wrap;word-break:break-word;margin:6px 0;}
.seekmodo-card .msg-paused{background:#fde2e2;color:#7a1a1a;border-left:4px solid #b91c1c;}
.seekmodo-card .msg-paused strong{display:block;margin-bottom:4px;}
.seekmodo-card .kv{display:grid;grid-template-columns:140px 1fr;gap:6px 12px;font-size:13px;margin:12px 0;}
.seekmodo-card .kv dt{color:#6b7280;}
.seekmodo-card .kv dd{margin:0;color:#111;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
.seekmodo-card table.transitions{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
.seekmodo-card table.transitions th,.seekmodo-card table.transitions td{padding:6px 8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;}
.seekmodo-card table.transitions th{background:#f9fafb;font-weight:600;}
.seekmodo-card table.transitions td.reason{color:#6b7280;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;}
.seekmodo-card table.diag{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}
.seekmodo-card table.diag th,.seekmodo-card table.diag td{padding:7px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;color:#111827;}
.seekmodo-card table.diag th{background:#f9fafb;font-weight:600;color:#374151;}
.seekmodo-card table.diag td.label{color:#111827;font-weight:500;}
.seekmodo-card table.diag td.value{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#111827;}
.seekmodo-card table.diag td.hint{color:#6b7280;font-size:12px;}
.seekmodo-card table.diag tr.sev-ok td.label::before{content:"\2713 ";color:#127436;font-weight:700;}
.seekmodo-card table.diag tr.sev-warn td.label::before{content:"\26A0 ";color:#a16207;font-weight:700;}
.seekmodo-card table.diag tr.sev-fail td.label::before{content:"\2717 ";color:#b91c1c;font-weight:700;}
.seekmodo-card table.diag tr.sev-info td.label::before{content:"\2022 ";color:#6b7280;}
.seekmodo-card table.diag tr.sev-warn{background:#fffdf6;}
.seekmodo-card table.diag tr.sev-fail{background:#fff7f7;}
</style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="seekmodo-card">
  <h1>Seekmodo Connect</h1>

  <?php if ($subscriptionCancelled): ?>
    <div class="msg msg-paused">
      <strong>Seekmodo account paused</strong>
      The gateway is returning <code>403 tenant_paused</code> for this
      store, so the storefront has fallen back to native search.
      Reactivate billing at
      <a href="https://seekmodo.com/billing" target="_blank" rel="noopener">seekmodo.com/billing</a>
      to bring search back online.
    </div>
  <?php endif; ?>

  <?php foreach ($messages as $m): ?>
    <div class="msg msg-<?= $m['type'] ?>"><?= htmlspecialchars($m['text'], ENT_QUOTES, CHARSET) ?></div>
  <?php endforeach; ?>

  <?php if (!$sodiumOk): ?>
    <?php
      $phpV = explode('.', PHP_VERSION);
      $eaPkg = 'ea-php' . ($phpV[0] ?? '8') . ($phpV[1] ?? '1') . '-php-sodium';
      $debPkg = 'php' . ($phpV[0] ?? '8') . '.' . ($phpV[1] ?? '1') . '-sodium';
      $fpmSvc = 'php' . ($phpV[0] ?? '8') . '.' . ($phpV[1] ?? '1') . '-fpm';
    ?>
    <div class="msg msg-error">
      <strong>PHP sodium extension missing — pairing will fail.</strong><br>
      The connector needs <code>sodium_crypto_sign_verify_detached</code> to verify
      the EdDSA pairing JWT from seekmodo.com. Your storefront is on PHP
      <code><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, CHARSET) ?></code>
      (SAPI <code><?= htmlspecialchars(PHP_SAPI, ENT_QUOTES, CHARSET) ?></code>)
      and the extension is not loaded.
      <br><br>
      <strong>Fix (cPanel / EasyApache):</strong>
      Run as root: <code>yum install -y <?= htmlspecialchars($eaPkg, ENT_QUOTES, CHARSET) ?></code>
      &nbsp;— PHP-FPM is restarted automatically. Then refresh this page.
      <br>
      <strong>Fix (Debian / Ubuntu):</strong>
      <code>apt-get install -y <?= htmlspecialchars($debPkg, ENT_QUOTES, CHARSET) ?> &amp;&amp; systemctl restart <?= htmlspecialchars($fpmSvc, ENT_QUOTES, CHARSET) ?></code>
    </div>
  <?php endif; ?>

  <?php if ($isPaired && !$apcuOk): ?>
    <?php
      // Mirrors the sodium block above but as a warning, not an error
      // — APCu missing means degraded performance / occasional
      // "gateway unreachable" flickers, not a broken connector.
      // Severity tier matches EnvProbe::SEV_WARN.
      $phpVa = explode('.', PHP_VERSION);
      $eaApcu  = 'ea-php' . ($phpVa[0] ?? '8') . ($phpVa[1] ?? '1') . '-pecl-apcu';
      $debApcu = 'php' . ($phpVa[0] ?? '8') . '.' . ($phpVa[1] ?? '1') . '-apcu';
      $fpmSvcA = 'php' . ($phpVa[0] ?? '8') . '.' . ($phpVa[1] ?? '1') . '-fpm';
      $apcuExtPresent = !empty($env['apcu_extension']);
      $supportSubject = 'Enable APCu PHP extension for ' . PHP_VERSION;
      $supportBody = "Hi,\n\nMy Zen Cart store needs the `apcu` PHP extension enabled "
        . "for PHP " . PHP_VERSION . ". Could you install the "
        . "`{$eaApcu}` package (cPanel/EasyApache 4) or `{$debApcu}` "
        . "(Debian/Ubuntu) and set `apc.enabled=1` in our php.ini? "
        . "It's a standard PECL extension and doesn't require any "
        . "additional configuration. The Seekmodo search plugin uses "
        . "it to cache configuration locally; without it, every admin "
        . "page render makes an outbound HTTPS request to "
        . "mcp.seekmodo.com.\n\nThanks!";
    ?>
    <div class="msg msg-warn">
      <strong>APCu missing &mdash; Seekmodo is running in degraded mode.</strong><br>
      <?php if ($apcuExtPresent): ?>
        The APCu extension is loaded but disabled (<code>apc.enabled=0</code>).
      <?php else: ?>
        The PHP APCu extension is not loaded on this server.
      <?php endif; ?>
      Without it, the connector reaches <code>mcp.seekmodo.com</code>
      on every admin page render and every storefront search burst,
      instead of riding a 5-minute cache. You may see brief
      &ldquo;gateway unreachable&rdquo; banners during normal network
      hiccups, and your storefront search will be slightly slower.
      Pairing and search still work.
      <details>
        <summary>Fix &mdash; cPanel / EasyApache 4 (root SSH or WHM)</summary>
        <pre>yum install -y <?= htmlspecialchars($eaApcu, ENT_QUOTES, CHARSET) ?>
# PHP-FPM is restarted automatically by the cPanel post-install hook.
# If you used WHM > Software > EasyApache 4 instead, click "Provision"
# to apply, then "Tools" > "Multi PHP INI Editor" and set
# apc.enabled = 1 for your storefront's PHP version.</pre>
      </details>
      <details>
        <summary>Fix &mdash; Debian / Ubuntu (root SSH)</summary>
        <pre>apt-get install -y <?= htmlspecialchars($debApcu, ENT_QUOTES, CHARSET) ?>
systemctl restart <?= htmlspecialchars($fpmSvcA, ENT_QUOTES, CHARSET) ?></pre>
      </details>
      <details>
        <summary>Fix &mdash; managed / shared hosting (no SSH access)</summary>
        Send your hosting provider this message:
        <pre><?= htmlspecialchars($supportBody, ENT_QUOTES, CHARSET) ?></pre>
        <a href="mailto:?subject=<?= rawurlencode($supportSubject) ?>&amp;body=<?= rawurlencode($supportBody) ?>" class="btn btn-secondary" style="margin-top:6px;">Open in email</a>
      </details>
      <p style="margin:10px 0 0 0;font-size:12px;color:#6b5400;">
        Once installed, click <strong>Refresh snapshot</strong> below
        and this banner will clear. The Diagnostics panel further
        down lists the full set of PHP extensions the connector cares
        about.
      </p>
    </div>
  <?php endif; ?>

  <p>
    <?php if ($isPaired): ?>
      <span class="badge badge-paired">Connected</span>
      tenant <code><?= htmlspecialchars($tenantId, ENT_QUOTES, CHARSET) ?></code>
    <?php else: ?>
      <span class="badge badge-unpaired">Not connected</span>
      This store hasn't been paired to a Seekmodo tenant yet.
    <?php endif; ?>
    <span class="badge badge-mode" style="margin-left:8px;"><?= htmlspecialchars($currentVersion, ENT_QUOTES, CHARSET) ?></span>
    <?php if ($updateAvailable): ?>
      <span class="badge badge-stale" style="margin-left:4px;"><?= htmlspecialchars($latestVersion, ENT_QUOTES, CHARSET) ?> available</span>
    <?php endif; ?>
  </p>

  <p>
    Settings (mode, auto-promote, timeouts, debug) are managed on
    <a href="https://admin.seekmodo.com/settings" target="_blank" rel="noopener">admin.seekmodo.com/settings</a>
    — that's the source of truth. The values below are the connector's
    current view of what the gateway has for this tenant.
  </p>

  <?php if ($isPaired): ?>
    <h2>Current snapshot</h2>
    <dl class="kv">
      <dt>Mode</dt>
      <dd>
        <span class="badge badge-mode"><?= htmlspecialchars((string)$mode, ENT_QUOTES, CHARSET) ?></span>
      </dd>
      <dt>FSM state</dt>
      <dd>
        <?php $stateBadgeClass = $autoState === 'enforced' ? 'badge-state-active' : 'badge-state'; ?>
        <span class="badge <?= $stateBadgeClass ?>"><?= htmlspecialchars($autoState !== '' ? $autoState : 'unknown', ENT_QUOTES, CHARSET) ?></span>
      </dd>
      <dt>State since</dt>
      <dd><?= htmlspecialchars($autoStateSince !== '' ? $autoStateSince : '—', ENT_QUOTES, CHARSET) ?></dd>
      <dt>Source</dt>
      <dd><?= $snapshot !== null ? 'gateway snapshot' : 'local cache (gateway unreachable)' ?></dd>
    </dl>

    <?php if ($lastTransitions !== []): ?>
      <h2>Last 5 transitions</h2>
      <table class="transitions">
        <thead><tr><th>When</th><th>From → To</th><th>Reason</th></tr></thead>
        <tbody>
          <?php foreach (array_reverse($lastTransitions) as $t): ?>
            <tr>
              <td><?= htmlspecialchars((string)($t['ts'] ?? ''), ENT_QUOTES, CHARSET) ?></td>
              <td><?= htmlspecialchars(((string)($t['from'] ?? '?')) . ' → ' . ((string)($t['to'] ?? '?')), ENT_QUOTES, CHARSET) ?></td>
              <td class="reason"><?= htmlspecialchars((string)($t['reason'] ?? ''), ENT_QUOTES, CHARSET) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (class_exists(EnvProbe::class)): ?>
      <h2>Diagnostics</h2>
      <p style="font-size:12px;color:#6b7280;margin:0 0 8px 0;">
        Snapshot of the PHP extensions and runtime config the connector
        depends on. <strong>Required</strong> rows in red mean a real
        feature is broken; <strong>recommended</strong> rows in yellow
        mean degraded performance / extra outbound traffic.
      </p>
      <table class="diag">
        <thead><tr><th>Check</th><th>Value</th><th>Hint</th></tr></thead>
        <tbody>
          <?php foreach (EnvProbe::diagnostics($env) as $row): ?>
            <tr class="sev-<?= htmlspecialchars($row['severity'], ENT_QUOTES, CHARSET) ?>">
              <td class="label"><?= htmlspecialchars($row['label'], ENT_QUOTES, CHARSET) ?></td>
              <td class="value"><?= htmlspecialchars($row['value'], ENT_QUOTES, CHARSET) ?></td>
              <td class="hint"><?= htmlspecialchars($row['hint'], ENT_QUOTES, CHARSET) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php
            // Locked-domain status — separate from the EnvProbe
            // extension grid because it depends on Zen Cart's
            // HTTPS_CATALOG_SERVER which isn't a PHP extension.
            // Same severity tiers / row class.
            list($ldSev, $ldCode, $ldDetail) = EnvProbe::lockedDomainStatus();
          ?>
          <tr class="sev-<?= htmlspecialchars($ldSev, ENT_QUOTES, CHARSET) ?>">
            <td class="label">Locked domain</td>
            <td class="value"><?= htmlspecialchars($ldCode, ENT_QUOTES, CHARSET) ?></td>
            <td class="hint"><?= htmlspecialchars($ldDetail, ENT_QUOTES, CHARSET) ?></td>
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h2>Actions</h2>
  <form method="post" action="<?= zen_href_link('numinix_seekmodo_connect.php') ?>" style="margin-top:8px;">
    <?php if (!empty($_SESSION['securityToken'])): ?>
      <input type="hidden" name="securityToken" value="<?= htmlspecialchars($_SESSION['securityToken'], ENT_QUOTES, CHARSET) ?>">
    <?php endif; ?>

    <?php if ($updateAvailable): ?>
      <button type="submit" name="action" value="apply_update" class="btn"
              onclick="return confirm('Download and install Seekmodo connector <?= htmlspecialchars($latestVersion, ENT_QUOTES, CHARSET) ?>? The current version is backed up automatically.');">
        Update to <?= htmlspecialchars($latestVersion, ENT_QUOTES, CHARSET) ?>
      </button>
    <?php endif; ?>

    <?php if ($isPaired): ?>
      <button type="submit" name="action" value="refresh" class="btn btn-secondary">
        Refresh snapshot
      </button>
      <button type="submit" name="action" value="pair" class="btn"<?= $sodiumOk ? '' : ' disabled title="PHP sodium extension is required — see warning above"' ?>>
        Re-pair
      </button>
      <a class="btn btn-secondary" href="https://admin.seekmodo.com/settings" target="_blank" rel="noopener">
        Manage settings
      </a>
      <?php if (defined('FILENAME_NUMINIX_SEEKMODO_UPDATES')): ?>
        <a class="btn btn-secondary" href="<?= zen_href_link(FILENAME_NUMINIX_SEEKMODO_UPDATES) ?>">
          Update details
        </a>
      <?php endif; ?>
    <?php else: ?>
      <button type="submit" name="action" value="pair" class="btn"<?= $sodiumOk ? '' : ' disabled title="PHP sodium extension is required — see warning above"' ?>>
        Connect to Seekmodo
      </button>
    <?php endif; ?>
  </form>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>

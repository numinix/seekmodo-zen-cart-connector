<?php
/**
 * Sprint 4 PR 2-4: in-plugin auto-update admin page.
 *
 * Sibling of `numinix_seekmodo_connect.php`.  Surfaces the current
 * connector version vs the latest in
 * https://seekmodo.com/plugins/manifest.json, plus a single
 * **Apply update** action that downloads the signed zip, re-verifies
 * SHA-256 + ed25519, snapshots the live tree, and runs the new
 * version's `ScriptedInstaller` upgrade entry-point.
 *
 * The page also exposes a **Roll back** action that swaps directories
 * back to a recent backup; see `Numinix\Seekmodo\UpdateApplier`.
 */

require 'includes/application_top.php';

use Numinix\Seekmodo\UpdateClient;
use Numinix\Seekmodo\UpdateApplier;

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

function _seekmodo_updates_check_csrf(): bool
{
    if (!empty($_POST['securityToken'])) {
        return isset($_SESSION['securityToken']) && hash_equals($_SESSION['securityToken'], $_POST['securityToken']);
    }
    return true;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

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

$updateClient = UpdateClient::fromRunningPlugin();
$messages = [];

if ($action === 'refresh' && _seekmodo_updates_check_csrf()) {
    $updateClient->invalidateCache();
    $messages[] = ['type' => 'success', 'text' => 'Manifest cache cleared — re-fetching fresh copy from seekmodo.com.'];
}

$envelope = $updateClient->pullManifest();
$entry = ($envelope !== null && isset($envelope['entry']) && is_array($envelope['entry'])) ? $envelope['entry'] : null;

$latestVersion = '';
$cmp = 0;
$updateAvailable = false;
if ($entry !== null) {
    $latestVersion = isset($entry['latest']) ? 'v' . ltrim((string)$entry['latest'], 'v') : '';
    if ($latestVersion !== '') {
        $cmp = $updateClient->compareVersions($currentVersion, $latestVersion);
        $updateAvailable = ($cmp < 0);
    }
}

$applier = UpdateApplier::fromRunningPlugin();
$backups = $applier->listBackups();
$applyDisabled = !$updateAvailable;

if ($action === 'apply' && _seekmodo_updates_check_csrf() && $updateAvailable && $entry !== null) {
    $result = $applier->apply($entry);
    if ($result['ok']) {
        $messages[] = [
            'type' => 'success',
            'text' => 'Connector upgraded to ' . $latestVersion . '. Backup of the previous version kept at ' . $result['backup_dir'] . '.',
        ];
        $currentVersion = $latestVersion;
        $cmp = 0;
        $updateAvailable = false;
        $applyDisabled = true;
        $backups = $applier->listBackups();
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Apply failed: ' . $result['error']];
    }
}

if ($action === 'rollback' && _seekmodo_updates_check_csrf()) {
    $target = isset($_POST['rollback_to']) ? (string)$_POST['rollback_to'] : '';
    $result = $applier->rollback($target);
    if ($result['ok']) {
        $messages[] = ['type' => 'success', 'text' => 'Rolled back to ' . $target . '. Restart the storefront if you have a long-running php-fpm pool.'];
        $currentVersion = $target;
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Rollback failed: ' . $result['error']];
    }
}

require DIR_WS_INCLUDES . 'admin_html_head.php';
?>
<!doctype html>
<html <?= HTML_PARAMS ?>>
<head>
<meta charset="<?= CHARSET ?>">
<title><?= TITLE ?> · Seekmodo Updates</title>
<link rel="stylesheet" href="includes/stylesheet.css">
<style>
.seekmodo-card{max-width:720px;margin:32px auto;padding:24px;border:1px solid #ddd;border-radius:8px;background:#fff;}
.seekmodo-card h1{margin:0 0 8px 0;font-size:22px;}
.seekmodo-card h2{margin:24px 0 8px 0;font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:#666;}
.seekmodo-card p{color:#444;line-height:1.5;}
.seekmodo-card .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;}
.seekmodo-card .badge-current{background:#e6f7ec;color:#127436;}
.seekmodo-card .badge-stale{background:#fde2e2;color:#7a1a1a;}
.seekmodo-card .badge-unknown{background:#fef3c7;color:#7a5b00;}
.seekmodo-card .btn{display:inline-block;background:#1f4ed8;color:#fff;padding:10px 18px;border:0;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;}
.seekmodo-card .btn-secondary{background:#fff;color:#1f4ed8;border:1px solid #1f4ed8;margin-left:8px;}
.seekmodo-card .btn:disabled{background:#cbd5e1;cursor:not-allowed;}
.seekmodo-card .msg{padding:10px 14px;border-radius:6px;margin:12px 0;font-size:13px;}
.seekmodo-card .msg-error{background:#fde2e2;color:#7a1a1a;}
.seekmodo-card .msg-success{background:#dcfce7;color:#166534;}
.seekmodo-card .kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px;font-size:13px;margin:12px 0;}
.seekmodo-card .kv dt{color:#6b7280;}
.seekmodo-card .kv dd{margin:0;color:#111;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
.seekmodo-card table.backups{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
.seekmodo-card table.backups th,.seekmodo-card table.backups td{padding:6px 8px;border-bottom:1px solid #eee;text-align:left;}
.seekmodo-card table.backups th{background:#f9fafb;font-weight:600;}
</style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="seekmodo-card">
  <h1>Seekmodo Updates</h1>

  <?php foreach ($messages as $m): ?>
    <div class="msg msg-<?= $m['type'] ?>"><?= htmlspecialchars($m['text'], ENT_QUOTES, CHARSET) ?></div>
  <?php endforeach; ?>

  <p>
    <?php if ($entry === null): ?>
      <span class="badge badge-unknown">Manifest unreachable</span>
      Could not pull
      <a href="https://seekmodo.com/plugins/manifest.json" target="_blank" rel="noopener">seekmodo.com/plugins/manifest.json</a>.
      The "Apply update" button is disabled until we have a verified manifest.
    <?php elseif ($updateAvailable): ?>
      <span class="badge badge-stale">Update available</span>
      A newer release is published on seekmodo.com. Review the release
      notes below, then click <strong>Apply update</strong> to install
      <?= htmlspecialchars($latestVersion, ENT_QUOTES, CHARSET) ?> in
      place. The current version is backed up automatically and can
      be restored with one click.
    <?php else: ?>
      <span class="badge badge-current">Up to date</span>
      You're on the latest published connector.
    <?php endif; ?>
  </p>

  <h2>Versions</h2>
  <dl class="kv">
    <dt>Installed</dt>
    <dd><?= htmlspecialchars($currentVersion, ENT_QUOTES, CHARSET) ?></dd>
    <dt>Latest published</dt>
    <dd><?= htmlspecialchars($latestVersion !== '' ? $latestVersion : '—', ENT_QUOTES, CHARSET) ?></dd>
    <?php if ($entry !== null): ?>
      <dt>Released at</dt>
      <dd><?= htmlspecialchars((string)($entry['released_at'] ?? '—'), ENT_QUOTES, CHARSET) ?></dd>
      <dt>SHA-256 (zip)</dt>
      <dd><?= htmlspecialchars(substr((string)($entry['sha256'] ?? ''), 0, 24), ENT_QUOTES, CHARSET) ?>…</dd>
      <dt>Signature kid</dt>
      <dd><?= htmlspecialchars((string)($entry['sig_kid'] ?? '—'), ENT_QUOTES, CHARSET) ?></dd>
      <dt>Min gateway</dt>
      <dd><?= htmlspecialchars((string)($entry['min_compatible_gateway'] ?? '—'), ENT_QUOTES, CHARSET) ?></dd>
    <?php endif; ?>
  </dl>

  <?php if ($entry !== null && isset($entry['release_notes_url'])): ?>
    <p>
      <a href="<?= htmlspecialchars((string)$entry['release_notes_url'], ENT_QUOTES, CHARSET) ?>" target="_blank" rel="noopener">
        Read release notes →
      </a>
    </p>
  <?php endif; ?>

  <h2>Actions</h2>
  <form method="post" action="<?= zen_href_link('numinix_seekmodo_updates.php') ?>" style="margin-top:8px;">
    <?php if (!empty($_SESSION['securityToken'])): ?>
      <input type="hidden" name="securityToken" value="<?= htmlspecialchars($_SESSION['securityToken'], ENT_QUOTES, CHARSET) ?>">
    <?php endif; ?>

    <button type="submit" name="action" value="apply" class="btn" <?= $applyDisabled ? 'disabled' : '' ?>>
      <?= $applyDisabled ? 'No update available' : 'Apply update to ' . htmlspecialchars($latestVersion, ENT_QUOTES, CHARSET) ?>
    </button>
    <button type="submit" name="action" value="refresh" class="btn btn-secondary">
      Re-check manifest
    </button>
  </form>

  <?php if ($backups !== []): ?>
    <h2>Recent backups</h2>
    <p>The plugin keeps the most recent 3 versions on disk so you can roll back without re-downloading.</p>
    <table class="backups">
      <thead><tr><th>Version</th><th>Backed up at</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($backups as $bk): ?>
          <tr>
            <td><?= htmlspecialchars((string)$bk['version'], ENT_QUOTES, CHARSET) ?></td>
            <td><?= htmlspecialchars(date('Y-m-d H:i', (int)$bk['mtime']), ENT_QUOTES, CHARSET) ?></td>
            <td>
              <form method="post" action="<?= zen_href_link('numinix_seekmodo_updates.php') ?>" style="display:inline;">
                <?php if (!empty($_SESSION['securityToken'])): ?>
                  <input type="hidden" name="securityToken" value="<?= htmlspecialchars($_SESSION['securityToken'], ENT_QUOTES, CHARSET) ?>">
                <?php endif; ?>
                <input type="hidden" name="rollback_to" value="<?= htmlspecialchars((string)$bk['version'], ENT_QUOTES, CHARSET) ?>">
                <button type="submit" name="action" value="rollback" class="btn btn-secondary"
                        onclick="return confirm('Roll back to <?= htmlspecialchars((string)$bk['version'], ENT_QUOTES, CHARSET) ?>?');">
                  Roll back
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>

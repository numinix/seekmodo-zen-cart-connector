<?php
/**
 * Admin home dashboard widget — Chat Console (AJAX-refreshed).
 *
 * @package SeekmodoAiChatbot
 */

if (!defined('FILENAME_SEEKMODO_AI_CHATBOT_CHATS')) {
    return;
}
if (!function_exists('zen_is_superuser') || !function_exists('check_page')) {
    return;
}
if (!zen_is_superuser() && !check_page(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, '')) {
    return;
}

try {
    $libraryHelper = dirname(__DIR__, 2) . '/seekmodo_ai_chatbot_library.php';
    if (is_file($libraryHelper)) {
        require_once $libraryHelper;
    }

    $chatArchiveClass = '\\SeekmodoAiChatbot\\ChatArchive';
    if (!class_exists($chatArchiveClass, false)) {
        if (!function_exists('seekmodo_ai_chatbot_require_library')) {
            return;
        }
        seekmodo_ai_chatbot_require_library(['ChatArchive.php', 'AdminPresence.php'], false);
        if (!class_exists($chatArchiveClass, false)) {
            return;
        }
    }

    if (!method_exists($chatArchiveClass, 'countOpenTickets')
        || !method_exists($chatArchiveClass, 'listOpenForDashboard')
    ) {
        return;
    }

    $chatArchiveClass::ensureTable();
    if (class_exists('\\SeekmodoAiChatbot\\AdminPresence', false)) {
        \SeekmodoAiChatbot\AdminPresence::touch((int) ($_SESSION['admin_id'] ?? 0));
    }
    $openCount = $chatArchiveClass::countOpenTickets();
    $awaitingCount = method_exists($chatArchiveClass, 'countAwaitingHuman')
        ? $chatArchiveClass::countAwaitingHuman()
        : 0;
    $tickets = $chatArchiveClass::listOpenForDashboard(10);
    $heading = defined('BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS')
        ? BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS
        : 'Chat Console';
    $listUrl = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, '');
    $awaitingUrl = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'awaiting=1');
    $pollUrl = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'ajax=inbox_poll');
    $cssUrl = function_exists('seekmodo_ai_chatbot_admin_asset_url')
        ? seekmodo_ai_chatbot_admin_asset_url('includes/css/seekmodo_ai_chatbot_admin.css')
        : 'includes/css/seekmodo_ai_chatbot_admin.css';
} catch (Throwable $e) {
    return;
}
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>">
<div class="nmx-panel ogc-dash-card" id="ogc-dash-inbox">
    <div class="nmx-panel-header ogc-dash-header">
        <div class="nmx-panel-title">
            <a href="<?php echo htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <span class="ogc-dash-open">(<span id="ogc-dash-open-count"><?php echo (int) $openCount; ?></span> open)</span>
            <span id="ogc-dash-awaiting-badge" class="ogc-dash-badge"<?php echo $awaitingCount > 0 ? '' : ' hidden'; ?>>
                <?php echo (int) $awaitingCount; ?> awaiting
            </span>
        </div>
        <?php if ($awaitingCount > 0): ?>
            <a class="nmx-btn nmx-btn-primary nmx-btn-sm" href="<?php echo htmlspecialchars($awaitingUrl, ENT_QUOTES, 'UTF-8'); ?>">View now</a>
        <?php endif; ?>
    </div>
    <div class="nmx-panel-body" style="padding:0">
        <table class="ogc-table ogc-dash-table">
            <tbody id="ogc-dash-tbody">
            <?php if ($tickets === []): ?>
                <tr><td class="ogc-empty">No open chats.</td></tr>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <?php
                    $id = (int) ($ticket['chat_id'] ?? 0);
                    $name = trim((string) ($ticket['customer_name'] ?? ''));
                    $email = trim((string) ($ticket['customer_email'] ?? ''));
                    $label = $name !== '' ? $name : ($email !== '' ? $email : 'Chat #' . $id);
                    $preview = trim((string) ($ticket['preview'] ?? ''));
                    $status = (string) ($ticket['status'] ?? 'open');
                    $updated = (string) ($ticket['date_updated'] ?? '');
                    $awaiting = !empty($ticket['awaiting_human']);
                    $present = method_exists($chatArchiveClass, 'isShopperPresent')
                        && $chatArchiveClass::isShopperPresent($ticket);
                    $presence = $present ? 'online' : 'offline';
                    $href = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'chat_id=' . $id);
                    $rowClass = $awaiting ? 'ogc-dash-awaiting' : ($status === 'live' ? 'ogc-dash-live' : '');
                    ?>
                    <tr class="<?php echo htmlspecialchars($rowClass, ENT_QUOTES, 'UTF-8'); ?>">
                        <td>
                            <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="contentlink">
                                #<?php echo $id; ?> &mdash; <?php echo htmlspecialchars(substr($label, 0, 28), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <?php if ($awaiting): ?>
                                <span class="ogc-dash-badge">Needs human</span>
                            <?php endif; ?>
                            <?php if ($preview !== ''): ?>
                                <div class="ogc-sub">
                                    <?php echo htmlspecialchars(substr($preview, 0, 60), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="ogc-dash-meta">
                            <span class="nmx-pill ogc-status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                            · <?php echo htmlspecialchars($presence, ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php echo htmlspecialchars(substr($updated, 0, 16), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
.ogc-dash-card{margin-bottom:16px}
.ogc-dash-header{flex-wrap:wrap}
.ogc-dash-open{font-weight:600;color:#6b7280;margin-left:4px;font-size:13px}
.ogc-dash-meta{white-space:nowrap;font-size:11px;text-align:right;vertical-align:top}
.ogc-dash-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:700;background:#dc2626;color:#fff;vertical-align:middle}
.ogc-dash-awaiting{background:#fef2f2!important}
.ogc-dash-awaiting td{border-left:3px solid #dc2626}
.ogc-dash-live td{border-left:3px solid #047857}
</style>
<script>
(function () {
  var pollUrl = <?php echo json_encode($pollUrl, JSON_UNESCAPED_SLASHES); ?>;
  var baseTitle = document.title;
  var blinkTimer = null;
  var lastSig = '';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function setTitleAlert(n) {
    if (blinkTimer) { clearInterval(blinkTimer); blinkTimer = null; }
    if (n < 1) { document.title = baseTitle; return; }
    var on = true;
    blinkTimer = setInterval(function () {
      document.title = on ? ('(' + n + ' awaiting) ' + baseTitle) : baseTitle;
      on = !on;
    }, 1200);
  }

  function render(data) {
    var n = parseInt(data.awaiting_count || 0, 10) || 0;
    setTitleAlert(n);
    var openEl = document.getElementById('ogc-dash-open-count');
    if (openEl) openEl.textContent = String(data.open_count || 0);
    var badge = document.getElementById('ogc-dash-awaiting-badge');
    if (badge) {
      if (n > 0) {
        badge.hidden = false;
        badge.textContent = n + ' awaiting';
      } else {
        badge.hidden = true;
      }
    }
    var tbody = document.getElementById('ogc-dash-tbody');
    if (!tbody) return;
    var items = data.items || [];
    if (!items.length) {
      tbody.innerHTML = '<tr><td class="ogc-empty">No open chats.</td></tr>';
      return;
    }
    var html = '';
    items.forEach(function (it) {
      var rowClass = it.awaiting ? 'ogc-dash-awaiting' : (it.status === 'live' ? 'ogc-dash-live' : '');
      var badgeHtml = it.awaiting ? ' <span class="ogc-dash-badge">Needs human</span>' : '';
      var preview = it.preview ? ('<div class="ogc-sub">' + esc(String(it.preview).slice(0, 60)) + '</div>') : '';
      var viewUrl = it.view_url || '';
      html += '<tr class="' + rowClass + '"><td>'
        + '<a class="contentlink" href="' + esc(viewUrl) + '">#' + esc(it.chat_id) + ' — ' + esc(String(it.label || '').slice(0, 28)) + '</a>'
        + badgeHtml + preview
        + '</td><td class="ogc-dash-meta">'
        + '<span class="nmx-pill ogc-status-' + esc(it.status) + '">' + esc(it.status) + '</span>'
        + ' · ' + (it.present ? 'online' : 'offline') + '<br>'
        + esc(String(it.updated || '').slice(0, 16))
        + '</td></tr>';
    });
    tbody.innerHTML = html;
  }

  function poll() {
    fetch(pollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) return;
        var sig = (data.awaiting_ids || []).join(',');
        render(data);
        lastSig = sig;
      })
      .catch(function () { /* ignore */ });
  }

  poll();
  setInterval(poll, 3000);
})();
</script>

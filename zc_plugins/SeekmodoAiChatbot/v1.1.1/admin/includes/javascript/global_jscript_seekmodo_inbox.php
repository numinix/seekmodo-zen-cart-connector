<?php
/**
 * Admin-wide awaiting-human badge in .adminHeaderAlerts (all admin pages).
 * Polls Chat Inbox inbox_poll; gated by the same page permission as the
 * Support Tickets dashboard widget.
 */
declare(strict_types=1);

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    return;
}
if (!defined('FILENAME_SEEKMODO_AI_CHATBOT_CHATS')) {
    return;
}
if (!function_exists('zen_is_superuser') || !function_exists('check_page')) {
    return;
}
if (!zen_is_superuser() && !check_page(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, '')) {
    return;
}

$currentPage = isset($PHP_SELF) ? basename((string) $PHP_SELF) : '';
$onInboxPage = $currentPage === FILENAME_SEEKMODO_AI_CHATBOT_CHATS . '.php';
$pollUrl = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'ajax=inbox_poll');
$listUrl = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'awaiting=1');
?>
<script>
(function () {
  var POLL_URL = <?php echo json_encode((string) $pollUrl, JSON_UNESCAPED_SLASHES); ?>;
  var LIST_URL = <?php echo json_encode((string) $listUrl, JSON_UNESCAPED_SLASHES); ?>;
  var ON_INBOX = <?php echo $onInboxPage ? 'true' : 'false'; ?>;
  var POLL_MS = ON_INBOX ? 15000 : 5000;
  var lastCount = -1;
  var titleBase = document.title || '';
  var blinkTimer = null;
  var badgeEl = null;

  function ensureBadge() {
    if (badgeEl && document.body.contains(badgeEl)) return badgeEl;
    var hosts = document.querySelectorAll('.adminHeaderAlerts');
    var host = hosts.length ? hosts[hosts.length - 1] : null;
    if (!host) return null;
    badgeEl = document.getElementById('seekmodo-inbox-header-badge');
    if (!badgeEl) {
      badgeEl = document.createElement('a');
      badgeEl.id = 'seekmodo-inbox-header-badge';
      badgeEl.className = 'btn btn-warning btn-sm';
      badgeEl.style.display = 'none';
      badgeEl.style.marginLeft = '6px';
      badgeEl.setAttribute('role', 'button');
      host.appendChild(badgeEl);
    }
    return badgeEl;
  }

  function stopBlink() {
    if (blinkTimer) {
      clearInterval(blinkTimer);
      blinkTimer = null;
    }
    document.title = titleBase;
  }

  function startBlink(count) {
    stopBlink();
    var flip = false;
    blinkTimer = setInterval(function () {
      flip = !flip;
      document.title = flip
        ? ('(' + count + ') awaiting — ' + titleBase)
        : titleBase;
    }, 1200);
  }

  function render(data) {
    var el = ensureBadge();
    if (!el || !data || !data.ok) return;
    var count = parseInt(data.awaiting_count, 10) || 0;
    var ids = Array.isArray(data.awaiting_ids) ? data.awaiting_ids : [];
    if (count < 1) {
      el.style.display = 'none';
      stopBlink();
      lastCount = 0;
      return;
    }
    var href = LIST_URL;
    if (count === 1 && ids.length === 1) {
      var item = (data.items || []).filter(function (row) {
        return parseInt(row.chat_id, 10) === parseInt(ids[0], 10);
      })[0];
      href = (item && item.view_url) ? item.view_url : (LIST_URL.replace('awaiting=1', 'chat_id=' + ids[0]));
    }
    el.href = href;
    el.textContent = count === 1
      ? '1 chat awaiting'
      : (count + ' chats awaiting');
    el.style.display = '';
    if (lastCount >= 0 && count > lastCount && !ON_INBOX) {
      startBlink(count);
    }
    lastCount = count;
  }

  function poll() {
    if (typeof fetch !== 'function') return;
    // Throttle when dashboard widget is already polling on home.
    if (document.getElementById('ogc-dash-inbox') && !ON_INBOX) {
      // Still refresh badge less often via longer interval; widget covers live UX.
    }
    fetch(POLL_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () { /* ignore */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', poll);
  } else {
    poll();
  }
  setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });
})();
</script>

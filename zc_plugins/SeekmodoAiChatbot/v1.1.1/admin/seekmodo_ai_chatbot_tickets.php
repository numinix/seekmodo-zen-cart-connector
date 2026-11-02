<?php
/**
 * Seekmodo Tickets - store admin view of the tenant's central ticket queue.
 *
 * Renders the shared Seekmodo ticket UI and proxies its calls to Seekmodo
 * tickets_api: signed with the chatbot's tenant pairing, acting as the
 * logged-in admin (matched to a Seekmodo support agent by email).
 */
require 'includes/application_top.php';

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

$ogcBoot = __DIR__ . '/seekmodo_ai_chatbot_bootstrap.php';
if (!is_file($ogcBoot) && defined('DIR_FS_CATALOG')) {
    $ogcBoots = glob(rtrim((string) DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot/v*/admin/seekmodo_ai_chatbot_bootstrap.php') ?: [];
    usort($ogcBoots, static function ($a, $b) {
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $a), $ma);
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $b), $mb);
        return version_compare($mb[1] ?? '0', $ma[1] ?? '0');
    });
    if (!empty($ogcBoots[0])) {
        $ogcBoot = $ogcBoots[0];
    }
}
require_once $ogcBoot;
seekmodo_ai_chatbot_require_admin_include('languages/english/seekmodo_ai_chatbot_tickets.php');
seekmodo_ai_chatbot_require_library(['SeekmodoTickets.php']);

use SeekmodoAiChatbot\SeekmodoTickets;

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

// JSON proxy: POST with X-Seekmodo-Admin-Token = session securityToken.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_GET['proxy'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $sentToken = (string) ($_SERVER['HTTP_X_SEEKMODO_ADMIN_TOKEN'] ?? '');
    if ($sentToken === '' || !isset($_SESSION['securityToken'])
        || !hash_equals((string) $_SESSION['securityToken'], $sentToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'bad_token']);
        exit;
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 512000) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'too_large']);
        exit;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json']);
        exit;
    }
    $action = (string) ($payload['action'] ?? '');
    if (!in_array($action, SeekmodoTickets::ADMIN_ACTIONS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action_not_allowed']);
        exit;
    }
    $agent = SeekmodoTickets::actingAgent($adminId);
    if ($agent === null) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'admin_email_required']);
        exit;
    }
    // The browser never chooses who it acts as.
    unset($payload['tenant_id'], $payload['acting_agent']);
    $payload['acting_agent'] = $agent;
    $result = SeekmodoTickets::dispatch($payload);
    if (empty($result['ok'])) {
        $err = (string) ($result['error'] ?? '');
        $notFound = in_array($err, ['not_found', 'ticket_not_found', 'chat_not_found'], true);
        http_response_code($notFound ? 404 : ($err === 'forbidden' ? 403 : 400));
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$paired = SeekmodoTickets::isPaired();
$openTicket = (int) ($_GET['ticket'] ?? 0);
$proxyUrl = str_replace('&amp;', '&', zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_TICKETS, 'proxy=1'));
$chatsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS);
$settingsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS);
$tenantId = '';
$creds = SeekmodoTickets::credentials();
if ($creds !== null) {
    $tenantId = $creds[0];
}
$adminUrl = 'https://admin.seekmodo.com/tickets' . ($tenantId !== '' ? '?t=' . rawurlencode($tenantId) : '');
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta charset="<?php echo CHARSET; ?>">
<title><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_TITLE, ENT_QUOTES); ?></title>
<link rel="stylesheet" href="includes/stylesheet.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(seekmodo_ai_chatbot_admin_asset_url('includes/css/seekmodo_ai_chatbot_admin.css'), ENT_QUOTES); ?>">
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>

<div class="nmx-module" id="seekmodo-tickets-app">
  <div class="nmx-page-header">
    <div>
      <div class="nmx-breadcrumb">
        <a href="<?php echo $settingsHref; ?>">Seekmodo AI Chatbot</a>
        <span class="sep"></span>
        <?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_TITLE, ENT_QUOTES); ?>
      </div>
      <h2><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_TITLE, ENT_QUOTES); ?></h2>
      <p class="nmx-page-header-subtitle"><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_SUBTITLE, ENT_QUOTES); ?></p>
    </div>
    <div class="nmx-page-header-actions">
      <a href="<?php echo $chatsHref; ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_OPEN_CHAT, ENT_QUOTES); ?></a>
    </div>
  </div>

  <?php if (!$paired): ?>
    <div class="nmx-alert nmx-alert-warning"><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_NOT_PAIRED, ENT_QUOTES); ?></div>
  <?php elseif (SeekmodoTickets::actingAgent($adminId) === null): ?>
    <div class="nmx-alert nmx-alert-warning"><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_NO_EMAIL, ENT_QUOTES); ?></div>
  <?php else: ?>
    <div id="seekmodo-tickets-root"></div>
    <script src="<?php echo htmlspecialchars(seekmodo_ai_chatbot_admin_asset_url('includes/js/seekmodo-tickets-admin.js'), ENT_QUOTES); ?>"></script>
    <script>
    (function () {
      var cfg = <?php echo json_encode([
          'proxy' => $proxyUrl,
          'token' => (string) ($_SESSION['securityToken'] ?? ''),
          'adminUrl' => $adminUrl,
          'openTicket' => $openTicket > 0 ? $openTicket : null,
      ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
      function request(payload) {
        return fetch(cfg.proxy, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Seekmodo-Admin-Token': cfg.token },
          body: JSON.stringify(payload)
        }).then(function (res) {
          return res.json().catch(function () { return { ok: false, error: 'http_' + res.status }; });
        });
      }
      if (!window.SeekmodoTicketsAdmin) {
        document.getElementById('seekmodo-tickets-root').textContent = 'Seekmodo Tickets could not load.';
        return;
      }
      window.SeekmodoTicketsAdmin.mount(document.getElementById('seekmodo-tickets-root'), request, {
        title: <?php echo json_encode(TEXT_SEEKMODO_TICKETS_TITLE, JSON_HEX_TAG); ?>,
        adminUrl: cfg.adminUrl,
        openTicket: cfg.openTicket
      });
    })();
    </script>
  <?php endif; ?>
</div>

<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>

<?php
/**
 * Seekmodo AI Chatbot — Support Ticket mailbox settings (IMAP + SMTP).
 */
require 'includes/application_top.php';

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

// Prefer copied admin includes; fall back to zc_plugins after deploy wipe.
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
seekmodo_ai_chatbot_require_admin_include('languages/english/seekmodo_ai_chatbot_mail.php');
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED', 'Poll new emails and open a ticket');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED_HELP')) {
    define(
        'TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED_HELP',
        'Off by default. Applies only to email that has no ticket number. Replies that already include the ticket number stay on that ticket.'
    );
}

// Keep this config page under Configuration (migration from Tools).
if (isset($db) && defined('TABLE_ADMIN_PAGES')) {
    $db->Execute(
        "UPDATE " . TABLE_ADMIN_PAGES . "
         SET menu_key = 'configuration',
             language_key = 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL'
         WHERE page_key = 'seekmodoAiChatbotMail'
           AND (menu_key <> 'configuration'
                OR language_key <> 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL')"
    );
}

seekmodo_ai_chatbot_require_library([
    'MailConfig.php',
    'EmailReplyExtractor.php',
    'ImapInbox.php',
    'ChatbotSmtpMailer.php',
    'TicketMail.php',
    'ChatArchive.php',
    'SeekmodoTickets.php',
    'InboundMailPoller.php',
], false);

use SeekmodoAiChatbot\ChatbotSmtpMailer;
use SeekmodoAiChatbot\ImapInbox;
use SeekmodoAiChatbot\InboundMailPoller;
use SeekmodoAiChatbot\MailConfig;

/** @var array<string, array{title:string,value:string,description:string,sort:int,set_function?:string}> $mailKeys */
$mailKeys = [
    'SEEKMODO_AI_CHATBOT_MAIL_ENABLED' => [
        'title' => 'Chatbot Mail: Enabled',
        'value' => 'false',
        'description' => 'Enable IMAP polling and SMTP sending for Support Tickets.',
        'sort' => 200,
        'set_function' => "zen_cfg_select_option(array('true', 'false'),",
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_ADDRESS' => [
        'title' => 'Chatbot Mail: Address',
        'value' => '',
        'description' => 'Shared From/Reply-To mailbox for Support Tickets.',
        'sort' => 201,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED' => [
        'title' => 'Chatbot Mail: Create Tickets From New Email',
        'value' => 'false',
        'description' => 'Off by default. When true, poll inbound email that has no ticket number and open a new support ticket. Replies that already include #id stay on that ticket.',
        'sort' => 202,
        'set_function' => "zen_cfg_select_option(array('true', 'false'),",
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_HOST' => [
        'title' => 'Chatbot Mail: IMAP Host', 'value' => '', 'description' => '', 'sort' => 210,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_PORT' => [
        'title' => 'Chatbot Mail: IMAP Port', 'value' => '993', 'description' => '', 'sort' => 211,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_ENCRYPTION' => [
        'title' => 'Chatbot Mail: IMAP Encryption', 'value' => 'ssl', 'description' => 'ssl, tls, or none', 'sort' => 212,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_FOLDER' => [
        'title' => 'Chatbot Mail: IMAP Folder', 'value' => 'INBOX', 'description' => '', 'sort' => 213,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_USER' => [
        'title' => 'Chatbot Mail: IMAP Username', 'value' => '', 'description' => '', 'sort' => 214,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_IMAP_PASSWORD' => [
        'title' => 'Chatbot Mail: IMAP Password', 'value' => '', 'description' => '', 'sort' => 215,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_SMTP_HOST' => [
        'title' => 'Chatbot Mail: SMTP Host', 'value' => '', 'description' => 'Blank copies IMAP host', 'sort' => 220,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_SMTP_PORT' => [
        'title' => 'Chatbot Mail: SMTP Port', 'value' => '587', 'description' => '', 'sort' => 221,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_SMTP_ENCRYPTION' => [
        'title' => 'Chatbot Mail: SMTP Encryption', 'value' => 'tls', 'description' => 'ssl, tls, or none', 'sort' => 222,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_SMTP_USER' => [
        'title' => 'Chatbot Mail: SMTP Username', 'value' => '', 'description' => 'Blank copies IMAP user', 'sort' => 223,
    ],
    'SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD' => [
        'title' => 'Chatbot Mail: SMTP Password', 'value' => '', 'description' => 'Blank copies IMAP password', 'sort' => 224,
    ],
];

function seekmodo_ai_chatbot_mail_group_id(): int
{
    global $db;
    $res = $db->Execute(
        "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP
        . " WHERE configuration_group_title = 'Seekmodo AI Chatbot' LIMIT 1"
    );
    return ($res && !$res->EOF) ? (int) $res->fields['configuration_group_id'] : 0;
}

function seekmodo_ai_chatbot_mail_ensure_keys(array $mailKeys): void
{
    global $db;
    $groupId = seekmodo_ai_chatbot_mail_group_id();
    if ($groupId < 1) {
        return;
    }
    foreach ($mailKeys as $key => $meta) {
        $check = $db->Execute(
            "SELECT configuration_id FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
        );
        if ($check && !$check->EOF) {
            continue;
        }
        $setFunction = !empty($meta['set_function'])
            ? "'" . $db->prepareInput((string) $meta['set_function']) . "'"
            : 'NULL';
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " (
                configuration_title, configuration_key, configuration_value,
                configuration_description, configuration_group_id, sort_order,
                date_added, last_modified, set_function
             ) VALUES (
                '" . $db->prepareInput($meta['title']) . "',
                '" . $db->prepareInput($key) . "',
                '" . $db->prepareInput((string) $meta['value']) . "',
                '" . $db->prepareInput((string) ($meta['description'] ?? '')) . "',
                " . (int) $groupId . ",
                " . (int) ($meta['sort'] ?? 200) . ",
                NOW(),
                NULL,
                {$setFunction}
             )"
        );
        if (!defined($key)) {
            define($key, (string) $meta['value']);
        }
    }
}

function seekmodo_ai_chatbot_mail_cfg(string $key, string $default = ''): string
{
    if (defined($key)) {
        return (string) constant($key);
    }
    global $db;
    $res = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
    if ($res && !$res->EOF) {
        $val = (string) $res->fields['configuration_value'];
        if (!defined($key)) {
            define($key, $val);
        }
        return $val;
    }
    return $default;
}

function seekmodo_ai_chatbot_mail_set(string $key, string $value): void
{
    global $db;
    $db->Execute(
        "UPDATE " . TABLE_CONFIGURATION
        . " SET configuration_value = '" . $db->prepareInput($value) . "', last_modified = NOW()"
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
}

seekmodo_ai_chatbot_mail_ensure_keys($mailKeys);

$flash = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = isset($_POST['securityToken'], $_SESSION['securityToken'])
        && hash_equals((string) $_SESSION['securityToken'], (string) $_POST['securityToken']);
    if (!$tokenOk) {
        $flash[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_TOKEN_ERROR];
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'save') {
            $enabled = !empty($_POST['mail_enabled']) ? 'true' : 'false';
            $createUnmatched = !empty($_POST['mail_create_unmatched']) ? 'true' : 'false';
            $address = trim((string) ($_POST['mail_address'] ?? ''));
            $imapHost = trim((string) ($_POST['imap_host'] ?? ''));
            $imapPort = trim((string) ($_POST['imap_port'] ?? '993'));
            $imapEnc = strtolower(trim((string) ($_POST['imap_encryption'] ?? 'ssl')));
            $imapFolder = trim((string) ($_POST['imap_folder'] ?? 'INBOX')) ?: 'INBOX';
            $imapUser = trim((string) ($_POST['imap_user'] ?? ''));
            $imapPass = (string) ($_POST['imap_password'] ?? '');
            $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
            $smtpPort = trim((string) ($_POST['smtp_port'] ?? '587'));
            $smtpEnc = strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls')));
            $smtpUser = trim((string) ($_POST['smtp_user'] ?? ''));
            $smtpPass = (string) ($_POST['smtp_password'] ?? '');

            if ($smtpHost === '') {
                $smtpHost = $imapHost;
            }
            if ($smtpUser === '') {
                $smtpUser = $imapUser;
            }

            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_ENABLED', $enabled);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED', $createUnmatched);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_ADDRESS', $address);
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL', $address);
            }
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_HOST', $imapHost);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_PORT', $imapPort);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_ENCRYPTION', $imapEnc);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_FOLDER', $imapFolder);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_USER', $imapUser);
            if (trim($imapPass) !== '') {
                seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_IMAP_PASSWORD', $imapPass);
            }
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_HOST', $smtpHost);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PORT', $smtpPort);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_ENCRYPTION', $smtpEnc);
            seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_USER', $smtpUser);
            if (trim($smtpPass) !== '') {
                seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD', $smtpPass);
            } elseif (trim($imapPass) !== '' && $smtpUser === $imapUser) {
                seekmodo_ai_chatbot_mail_set('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD', $imapPass);
            }
            $flash[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_SAVED];
        } elseif ($action === 'test_imap') {
            try {
                if (!ImapInbox::extensionLoaded()) {
                    throw new RuntimeException('PHP imap extension is not loaded.');
                }
                $inbox = new ImapInbox(MailConfig::imap());
                $inbox->connect();
                $inbox->disconnect();
                $flash[] = ['type' => 'success', 'text' => 'IMAP connection succeeded.'];
            } catch (Throwable $e) {
                $flash[] = ['type' => 'danger', 'text' => 'IMAP test failed: ' . $e->getMessage()];
            }
        } elseif ($action === 'test_smtp') {
            $to = MailConfig::address();
            if ($to === '') {
                $flash[] = ['type' => 'danger', 'text' => 'Set a mailbox address before testing SMTP.'];
            } else {
                $result = ChatbotSmtpMailer::send(
                    'Support Tickets',
                    $to,
                    '[Support Tickets] SMTP test',
                    "SMTP test from Seekmodo AI Chatbot Support Ticket Mail.\n",
                    defined('STORE_NAME') ? (string) STORE_NAME : 'Store',
                    $to,
                    'seekmodo_ai_chatbot_support'
                );
                if (!empty($result['ok'])) {
                    $flash[] = ['type' => 'success', 'text' => 'SMTP test message sent to ' . $to . '.'];
                } else {
                    $flash[] = ['type' => 'danger', 'text' => 'SMTP test failed: ' . ($result['error'] ?? 'unknown')];
                }
            }
        } elseif ($action === 'poll_now') {
            $result = InboundMailPoller::run(40);
            $summary = 'Processed ' . (int) $result['processed'] . ', skipped ' . (int) $result['skipped'] . '.';
            if (!empty($result['errors'])) {
                $flash[] = ['type' => 'warning', 'text' => $summary . ' ' . implode(' | ', $result['errors'])];
            } else {
                $flash[] = ['type' => 'success', 'text' => $summary];
            }
            if (!empty($result['lines'])) {
                $flash[] = ['type' => 'info', 'text' => '<pre style="white-space:pre-wrap;margin:0">'
                    . htmlspecialchars(implode("\n", $result['lines']), ENT_QUOTES, 'UTF-8') . '</pre>'];
            }
        }
    }
}

$enabled = strtolower(seekmodo_ai_chatbot_mail_cfg('SEEKMODO_AI_CHATBOT_MAIL_ENABLED', 'false')) === 'true';
$createUnmatched = strtolower(seekmodo_ai_chatbot_mail_cfg('SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED', 'false')) === 'true';
$hasImapPass = seekmodo_ai_chatbot_mail_cfg('SEEKMODO_AI_CHATBOT_MAIL_IMAP_PASSWORD') !== '';
$hasSmtpPass = seekmodo_ai_chatbot_mail_cfg('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD') !== '';

$settingsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS);
$chatsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS);
$cssUrl = function_exists('seekmodo_ai_chatbot_admin_asset_url')
    ? seekmodo_ai_chatbot_admin_asset_url('includes/css/seekmodo_ai_chatbot_admin.css')
    : 'includes/css/seekmodo_ai_chatbot_admin.css';

$imapFields = [
    'imap_host' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_HOST, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_IMAP_HOST'],
    'imap_port' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_PORT, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_IMAP_PORT'],
    'imap_encryption' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_ENCRYPTION, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_IMAP_ENCRYPTION'],
    'imap_folder' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_FOLDER, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_IMAP_FOLDER'],
    'imap_user' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_USER, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_IMAP_USER'],
];
$smtpFields = [
    'smtp_host' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_HOST, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_SMTP_HOST'],
    'smtp_port' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_PORT, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_SMTP_PORT'],
    'smtp_encryption' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_ENCRYPTION, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_SMTP_ENCRYPTION'],
    'smtp_user' => ['label' => TEXT_SEEKMODO_AI_CHATBOT_MAIL_USER, 'key' => 'SEEKMODO_AI_CHATBOT_MAIL_SMTP_USER'],
];
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta charset="<?php echo CHARSET; ?>">
<title><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_HEADING; ?></title>
<link rel="stylesheet" href="includes/stylesheet.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES); ?>">
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>

<div class="nmx-module" id="openai-gpt-mail-app">
  <div class="nmx-page-header">
    <div>
      <div class="nmx-breadcrumb">
        <a href="<?php echo htmlspecialchars($settingsHref, ENT_QUOTES); ?>">Seekmodo AI Chatbot</a>
        <span class="sep"></span>
        <?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_HEADING, ENT_QUOTES); ?>
      </div>
      <h2><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_HEADING, ENT_QUOTES); ?></h2>
      <p class="nmx-page-header-subtitle"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_SUBTITLE, ENT_QUOTES); ?></p>
    </div>
    <div class="nmx-page-header-actions">
      <a href="<?php echo htmlspecialchars($chatsHref, ENT_QUOTES); ?>"><?php echo htmlspecialchars(BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS, ENT_QUOTES); ?></a>
      <a href="<?php echo htmlspecialchars($settingsHref, ENT_QUOTES); ?>"><?php echo htmlspecialchars(BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS, ENT_QUOTES); ?></a>
    </div>
  </div>

  <?php foreach ($flash as $msg): ?>
  <div class="nmx-alert nmx-alert-<?php echo htmlspecialchars($msg['type'], ENT_QUOTES); ?>">
    <?php echo $msg['text']; ?>
  </div>
  <?php endforeach; ?>

  <form method="post" class="nmx-form-narrow">
    <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
    <input type="hidden" name="action" value="save">

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title">Mailbox</div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-group">
          <label class="nmx-check">
            <input type="checkbox" name="mail_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
            <?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_ENABLED; ?>
          </label>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-check">
            <input type="checkbox" name="mail_create_unmatched" value="1" <?php echo $createUnmatched ? 'checked' : ''; ?>>
            <?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED; ?>
          </label>
          <p class="nmx-form-help"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED_HELP, ENT_QUOTES); ?></p>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-mail-address"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_ADDRESS; ?></label>
          <input class="nmx-form-control" type="email" id="ogc-mail-address" name="mail_address"
                 value="<?php echo htmlspecialchars(seekmodo_ai_chatbot_mail_cfg('SEEKMODO_AI_CHATBOT_MAIL_ADDRESS'), ENT_QUOTES); ?>">
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_IMAP, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-grid">
          <?php foreach ($imapFields as $name => $meta): ?>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"><?php echo $meta['label']; ?></label>
            <input class="nmx-form-control" type="text" id="ogc-<?php echo htmlspecialchars($name, ENT_QUOTES); ?>" name="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"
                   value="<?php echo htmlspecialchars(seekmodo_ai_chatbot_mail_cfg($meta['key']), ENT_QUOTES); ?>">
          </div>
          <?php endforeach; ?>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-imap-password"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD; ?></label>
          <input class="nmx-form-control" type="password" id="ogc-imap-password" name="imap_password" value="" autocomplete="new-password">
          <p class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD_KEEP; ?><?php echo $hasImapPass ? ' (saved)' : ''; ?></p>
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_MAIL_SMTP, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-grid">
          <?php foreach ($smtpFields as $name => $meta): ?>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"><?php echo $meta['label']; ?></label>
            <input class="nmx-form-control" type="text" id="ogc-<?php echo htmlspecialchars($name, ENT_QUOTES); ?>" name="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"
                   value="<?php echo htmlspecialchars(seekmodo_ai_chatbot_mail_cfg($meta['key']), ENT_QUOTES); ?>">
          </div>
          <?php endforeach; ?>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-smtp-password"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD; ?></label>
          <input class="nmx-form-control" type="password" id="ogc-smtp-password" name="smtp_password" value="" autocomplete="new-password">
          <p class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD_KEEP; ?><?php echo $hasSmtpPass ? ' (saved)' : ''; ?></p>
        </div>
      </div>
      <div class="nmx-panel-footer nmx-sticky-footer">
        <button type="submit" class="nmx-btn nmx-btn-primary"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_SAVE; ?></button>
      </div>
    </div>
  </form>

  <div class="nmx-panel nmx-form-narrow">
    <div class="nmx-panel-header">
      <div class="nmx-panel-title">Diagnostics</div>
    </div>
    <div class="nmx-panel-body">
      <div class="nmx-actions">
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="test_imap">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_TEST_IMAP; ?></button>
        </form>
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="test_smtp">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_TEST_SMTP; ?></button>
        </form>
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="poll_now">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_POLL_NOW; ?></button>
        </form>
      </div>
      <p class="nmx-help" style="margin-top:16px"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAIL_CRON_HELP; ?></p>
    </div>
  </div>
</div>

<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php';

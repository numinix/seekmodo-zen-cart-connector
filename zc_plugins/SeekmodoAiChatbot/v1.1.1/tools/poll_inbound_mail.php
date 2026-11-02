<?php
/**
 * Poll Support Ticket mailbox for inbound replies.
 *
 * Usage:
 *   php poll_inbound_mail.php --catalog=/path/to/catalog/root [--host=www.example.com]
 */

declare(strict_types=1);

$catalogDir = '';
$hostOverride = '';
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--catalog=') === 0) {
        $catalogDir = substr($arg, 10);
    }
    if (strpos($arg, '--host=') === 0) {
        $hostOverride = substr($arg, 7);
    }
}
if ($catalogDir === '') {
    if (defined('DIR_FS_CATALOG') && DIR_FS_CATALOG !== '') {
        $catalogDir = DIR_FS_CATALOG;
    } else {
        $catalogDir = realpath(__DIR__ . '/../../../../');
    }
}
if ($catalogDir === false || !is_dir($catalogDir)) {
    fwrite(STDERR, "ERROR: Catalog directory not found: {$catalogDir}\n");
    exit(1);
}
$catalogDir = rtrim(str_replace('\\', '/', (string) $catalogDir), '/');

if ($hostOverride !== '') {
    $httpHost = $hostOverride;
} elseif (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost') {
    $httpHost = (string) $_SERVER['HTTP_HOST'];
} else {
    $httpHost = 'localhost';
    if (preg_match('#/(?:public_html|www)/(?:([^/]+)/)?$#', $catalogDir . '/', $m) && !empty($m[1])) {
        $httpHost = $m[1];
    }
}

$_SERVER['HTTP_HOST'] = $httpHost;
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
chdir($catalogDir);

if (!defined('IS_ADMIN_FLAG')) {
    define('IS_ADMIN_FLAG', false);
}

require_once $catalogDir . '/includes/configure.php';
require_once $catalogDir . '/includes/database_tables.php';
require_once $catalogDir . '/includes/classes/class.base.php';
require_once $catalogDir . '/includes/classes/db/mysql/query_factory.php';

$db = new queryFactory();
if (!$db->connect(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, 'db_link', false)) {
    fwrite(STDERR, "ERROR: Database connection failed for host {$httpHost}\n");
    exit(1);
}
$GLOBALS['db'] = $db;

$configResult = $db->Execute(
    "SELECT configuration_key, configuration_value
       FROM " . TABLE_CONFIGURATION . "
      WHERE configuration_key LIKE 'SEEKMODO_AI_CHATBOT_%'
         OR configuration_key LIKE 'NUMINIX_SEEKMODO_%'
         OR configuration_key IN ('STORE_OWNER', 'STORE_OWNER_EMAIL_ADDRESS')"
);
while (!$configResult->EOF) {
    $key = (string) $configResult->fields['configuration_key'];
    if ($key !== '' && !defined($key)) {
        define($key, (string) $configResult->fields['configuration_value']);
    }
    $configResult->MoveNext();
}

$libBase = __DIR__ . '/../catalog/includes/library/SeekmodoAiChatbot/';
spl_autoload_register(static function (string $class) use ($libBase): void {
    $prefix = 'SeekmodoAiChatbot\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $libBase . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use SeekmodoAiChatbot\InboundMailPoller;

$result = InboundMailPoller::run(40);
foreach ($result['lines'] as $line) {
   fwrite(STDOUT, $line . "\n");
}
foreach ($result['errors'] as $err) {
    fwrite(STDERR, 'ERROR: ' . $err . "\n");
}
fwrite(STDOUT, 'processed=' . $result['processed'] . ' skipped=' . $result['skipped'] . "\n");
exit(!empty($result['ok']) || $result['processed'] > 0 ? 0 : (empty($result['errors']) ? 0 : 1));

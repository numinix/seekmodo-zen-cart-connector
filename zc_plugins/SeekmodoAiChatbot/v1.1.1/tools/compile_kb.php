<?php
/**
 * Knowledge Base Compiler for Seekmodo AI Chatbot.
 *
 * Scans define-page html_includes and active EZ-pages, compiles them
 * into markdown files under the configured KB cache directory.
 *
 * Usage:
 *   php compile_kb.php --catalog=/path/to/catalog/root [--host=dev.example.com]
 */

declare(strict_types=1);

// Determine catalog root / optional host override
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
        // zc_plugins/SeekmodoAiChatbot/vX.Y.Z/tools → store catalog root
        $catalogDir = realpath(__DIR__ . '/../../../../');
    }
}
if ($catalogDir === false || !is_dir($catalogDir)) {
    fwrite(STDERR, "ERROR: Catalog directory not found: {$catalogDir}\n");
    exit(1);
}
$catalogDir = rtrim(str_replace('\\', '/', (string) $catalogDir), '/');

// Pick HTTP_HOST so configure.php selects the right DB credentials.
// Defaulting to "localhost" falls through to production DB settings.
if ($hostOverride !== '') {
    $httpHost = $hostOverride;
} elseif (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost') {
    $httpHost = (string) $_SERVER['HTTP_HOST'];
} else {
    $httpHost = inferHttpHostFromCatalogPath($catalogDir);
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
    fwrite(STDERR, "ERROR: Database connection failed for host {$httpHost} (DB_SERVER=" . DB_SERVER . ").\n");
    exit(1);
}
$GLOBALS['db'] = $db;

// Promote chatbot config rows to constants for KbStore path etc.
$configResult = $db->Execute(
    "SELECT configuration_key, configuration_value
       FROM " . TABLE_CONFIGURATION . "
      WHERE configuration_key LIKE 'SEEKMODO_AI_CHATBOT_%'"
);
while (!$configResult->EOF) {
    $key = (string) $configResult->fields['configuration_key'];
    if ($key !== '' && !defined($key)) {
        define($key, (string) $configResult->fields['configuration_value']);
    }
    $configResult->MoveNext();
}

// Autoload library from the plugin package (catalog overlay is web-only).
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

use SeekmodoAiChatbot\KbStore;

$kbPath = defined('SEEKMODO_AI_CHATBOT_KB_PATH') ? SEEKMODO_AI_CHATBOT_KB_PATH : '';
$store = new KbStore($kbPath !== '' ? $kbPath : null);
$store->ensurePath();

// Stub common Zen Cart helpers that define-page templates may call.
stubZenCartHelpers();

$entries = [];
$count = 0;
$skipped = 0;

// ---- 1. Define pages (html_includes) ----
$templateDirs = [
    $catalogDir . '/includes/languages/english/html_includes/',
    $catalogDir . '/includes/languages/english/html_includes/genesis/',
];

foreach ($templateDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $files = glob($dir . '/define_*.php') ?: [];
    foreach ($files as $file) {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        $slug = str_replace('define_', '', $basename);

        $html = renderDefinePageSafely($file, $slug);
        if ($html === null) {
            $skipped++;
            continue;
        }

        $title = guessPageTitle($slug, $html);
        $text = stripPageContent($html);

        if (trim($text) === '') {
            continue;
        }

        $store->savePage($slug, $title, $text);
        $entries[] = [
            'title' => $title,
            'slug' => $slug,
            'updated' => date('Y-m-d H:i:s'),
        ];
        $count++;
        echo "  KB: {$slug} → {$title}\n";
    }
}

// ---- 2. EZ-pages from database ----
if (defined('TABLE_EZPAGES')) {
    $langId = 1;
    $contentTable = defined('TABLE_EZPAGES_CONTENT')
        ? TABLE_EZPAGES_CONTENT
        : (TABLE_EZPAGES . '_content');
    $ezResult = $db->Execute(
        "SELECT e.pages_id, ec.pages_title, ec.pages_html_text
           FROM " . TABLE_EZPAGES . " e
           INNER JOIN {$contentTable} ec
             ON e.pages_id = ec.pages_id AND ec.languages_id = " . $langId . "
          WHERE e.status_visible = 1
            AND ec.pages_html_text IS NOT NULL
            AND ec.pages_html_text != ''
            AND ec.pages_title != ''
          ORDER BY ec.pages_title"
    );
    while (!$ezResult->EOF) {
        $title = (string) ($ezResult->fields['pages_title'] ?? '');
        $html = (string) ($ezResult->fields['pages_html_text'] ?? '');
        $slug = 'ezpage-' . (int) $ezResult->fields['pages_id'] . '-' . slugify($title);

        $text = stripPageContent($html);
        if (trim($text) === '') {
            $ezResult->MoveNext();
            continue;
        }

        $store->savePage($slug, $title, $text);
        $entries[] = [
            'title' => $title,
            'slug' => $slug,
            'updated' => date('Y-m-d H:i:s'),
        ];
        $count++;
        echo "  KB: {$slug} → {$title}\n";
        $ezResult->MoveNext();
    }
}

$store->saveIndex($entries);

echo "\nCompiled {$count} KB pages" . ($skipped > 0 ? " ({$skipped} skipped)" : '') . ".\n";
exit(0);

// ---- Helper functions ----

function inferHttpHostFromCatalogPath(string $catalogDir): string
{
    if (defined('HTTP_SERVER') && HTTP_SERVER !== '') {
        $parts = parse_url((string) HTTP_SERVER);
        if (!empty($parts['host'])) {
            return (string) $parts['host'];
        }
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        return (string) preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
    }
    $normalized = str_replace('\\', '/', $catalogDir);
    if (preg_match('#/public_html/([^/]+)/?$#', $normalized, $m) && $m[1] !== '') {
        // Best-effort CLI fallback: last public_html segment (dev/staging/demo).
        return $m[1] . '.example.com';
    }
    return 'www.example.com';
}

function stubZenCartHelpers(): void
{
    if (!function_exists('zen_href_link')) {
        function zen_href_link($page = '', $parameters = '', $connection = 'NONSSL')
        {
            $page = (string) $page;
            $parameters = (string) $parameters;
            return '/' . ltrim($page, '/') . ($parameters !== '' ? '?' . $parameters : '');
        }
    }
    if (!function_exists('zen_image')) {
        function zen_image($src = '', $alt = '')
        {
            return '<img src="' . htmlspecialchars((string) $src, ENT_QUOTES) . '" alt="' . htmlspecialchars((string) $alt, ENT_QUOTES) . '">';
        }
    }
    if (!function_exists('zen_draw_separator')) {
        function zen_draw_separator()
        {
            return '';
        }
    }
}

/**
 * @return string|null HTML on success, null when the define page cannot be rendered
 */
function renderDefinePageSafely(string $file, string $slug): ?string
{
    ob_start();
    try {
        include $file;
        $html = ob_get_clean() ?: '';
        if (trim(strip_tags($html)) !== '') {
            return $html;
        }
        // Empty output (e.g. fatal recovered as blank) — try static fallback.
        $fallback = extractDefinePageHtmlFallback($file);
        if ($fallback !== null) {
            echo "  FALLBACK: {$slug} (static HTML; runtime include produced no text)\n";
            return $fallback;
        }
        return $html;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fallback = extractDefinePageHtmlFallback($file);
        if ($fallback !== null) {
            echo "  FALLBACK: {$slug} (static HTML; " . $e->getMessage() . ")\n";
            return $fallback;
        }
        echo "  SKIP: {$slug} (" . $e->getMessage() . ")\n";
        return null;
    }
}

/**
 * Strip PHP from a define-page file when include() cannot run (missing
 * shipping bootstrap, application_top, etc.) so KB still gets the copy.
 */
function extractDefinePageHtmlFallback(string $file): ?string
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $html = preg_replace('/<\?(?:php)?[\s\S]*?\?>/i', '', $raw) ?? '';
    // Trailing PHP without closing tag
    $html = preg_replace('/<\?(?:php)?[\s\S]*$/i', '', $html) ?? $html;
    if (trim(strip_tags($html)) === '') {
        return null;
    }
    return $html;
}

function stripPageContent(string $html): string
{
    $html = preg_replace('/<a\s[^>]*?(?:edit|admin|zenid)[^>]*?>.*?<\/a>/is', '', $html) ?? $html;
    $html = preg_replace('/<div\s[^>]*?id=["\'](?:nav|menu|header|footer|adminBar|adminWrap)["\'][^>]*?>.*?<\/div>/is', '', $html) ?? $html;

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text) ?? $text;
    return trim($text);
}

function guessPageTitle(string $slug, string $html): string
{
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        return trim(strip_tags($m[1]));
    }
    if (preg_match('/<h3 class="mb-0"[^>]*>(.*?)<\/h3>/is', $html, $m) && $slug === 'gv_faq') {
        return 'Frequently Asked Questions';
    }

    $known = [
        'gv_faq' => 'Frequently Asked Questions',
        'delivery' => 'Delivery Guide',
        'shipping_and_returns' => 'Returns Policy',
        'shippinginfo' => 'Shipping Information',
        'contact_us' => 'Contact Us',
    ];
    if (isset($known[$slug])) {
        return $known[$slug];
    }

    $title = str_replace(['_', '-'], ' ', $slug);
    return ucwords($title);
}

function slugify(string $text): string
{
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '');
    return trim(preg_replace('/-+/', '-', $s) ?? '', '-');
}

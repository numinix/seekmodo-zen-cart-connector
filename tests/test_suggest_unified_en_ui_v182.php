<?php
/**
 * v1.3.82: unpaid/over_quota keeps modern Suggest chrome (no legacy force).
 *
 *     php tests/test_suggest_unified_en_ui_v182.php
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$base = $repoRoot . DIRECTORY_SEPARATOR . 'zc_plugins' . DIRECTORY_SEPARATOR . 'Seekmodo';
$best = null;
$bestParts = [-1, -1, -1];
foreach (glob($base . DIRECTORY_SEPARATOR . 'v1.3.*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $name, $m)) {
        continue;
    }
    $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if ($parts > $bestParts) {
        $bestParts = $parts;
        $best = $dir;
    }
}

function sue_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

sue_assert(is_string($best) && is_dir($best), 'found a v1.3.x tree');
$ver = basename((string) $best);
sue_assert(version_compare(ltrim($ver, 'v'), '1.3.82', '>='), 'latest tree is v1.3.82+, got ' . $ver);

$observer = (string) file_get_contents(
    $best . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php'
);
sue_assert($observer !== '', 'observer readable');

// useLegacy must NOT call shouldPreferLocalSuggest.
$useLegacySlice = '';
if (preg_match('/private function useLegacy\(\): bool\s*\{.*?\n    \}/s', $observer, $m) === 1) {
    $useLegacySlice = $m[0];
}
sue_assert($useLegacySlice !== '', 'useLegacy() present');
sue_assert(
    strpos($useLegacySlice, 'shouldPreferLocalSuggest') === false,
    'useLegacy must not force legacy on shouldPreferLocalSuggest'
);
sue_assert(
    strpos($observer, "'prefer-local'") !== false
    || strpos($observer, 'prefer-local') !== false,
    'observer stamps prefer-local'
);
sue_assert(
    strpos($observer, 'prefer_local') !== false,
    'autoboot CFG includes prefer_local'
);

$bundle = (string) file_get_contents(
    $best . '/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js'
);
sue_assert(strpos($bundle, 'prefer-local') !== false, 'vendored bundle observes prefer-local');
sue_assert(strpos($bundle, 'products_id') !== false, 'vendored bundle parses products_id');

$en = (string) file_get_contents(
    $best . '/catalog/includes/functions/numinix_seekmodo_enhanced_native_lib.php'
);
sue_assert(
    strpos($en, "_numinix_seekmodo_typeahead_attach_image_url") !== false
    || strpos($en, 'image_url') !== false,
    'EN local typeahead attaches image_url'
);

$shim = (string) file_get_contents($best . '/catalog/numinix_seekmodo_suggest.php');
sue_assert(strpos($shim, "'rows'") !== false, 'suggest shim emits rows for CE merge');

fwrite(STDOUT, "OK suggest_unified_en_ui {$ver}\n");

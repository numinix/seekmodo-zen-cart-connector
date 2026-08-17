<?php

declare(strict_types=1);

/**
 * v1.3.69: installer default is the subscribed split-rail suggest widget.
 *
 *     php tests/test_suggest_default_modern_v169.php
 */

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

function sdm_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

sdm_assert(is_string($best) && is_dir($best), 'found a v1.3.x tree');
$ver = basename((string) $best);
sdm_assert(
    version_compare(ltrim($ver, 'v'), '1.3.69', '>='),
    'latest tree is v1.3.69+, got ' . $ver
);

$installer = (string) file_get_contents($best . '/Installer/ScriptedInstaller.php');
sdm_assert($installer !== '', 'installer readable');
sdm_assert(
    strpos($installer, "addConfigurationKey('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY'") !== false,
    'installer creates SUGGEST_USE_LEGACY'
);
sdm_assert(
    preg_match(
        "/addConfigurationKey\\('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY'.*?configuration_value'\\s*=>\\s*'false'/s",
        $installer
    ) === 1,
    'SUGGEST_USE_LEGACY default is false'
);
sdm_assert(
    preg_match(
        "/addConfigurationKey\\('NUMINIX_SEEKMODO_SUGGEST_ENABLED'.*?configuration_value'\\s*=>\\s*'true'/s",
        $installer
    ) === 1,
    'SUGGEST_ENABLED default is true'
);
sdm_assert(
    strpos($installer, 'function resetLeftoverLegacySuggest') !== false,
    'upgrade resets leftover USE_LEGACY=true'
);
sdm_assert(
    strpos($installer, "AND LOWER(configuration_value) IN ('true', '1', 'yes', 'on')") !== false,
    'reset matches truthy leftover values'
);

$init = (string) file_get_contents(
    $best . '/catalog/includes/init_includes/init_numinix_seekmodo.php'
);
sdm_assert(
    strpos($init, "define('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY', 'false')") !== false,
    'init defines USE_LEGACY false when missing'
);

$observer = (string) file_get_contents(
    $best . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php'
);
sdm_assert(
    strpos($observer, "return 'split-rail';") !== false,
    'observer default layout is split-rail'
);
sdm_assert(
    preg_match(
        '/if \\(!defined\\(\'NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY\'\\)\\) \\{\\s*return false;/s',
        $observer
    ) === 1,
    'useLegacy() returns false when the constant is missing'
);

fwrite(STDOUT, "OK suggest_default_modern {$ver}\n");

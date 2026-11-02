<?php
/**
 * Regression: after a 401 the suggest bundle must fetch a new browser
 * token instead of re-sending the cached one. Without this, gateway-direct
 * stores stay on 401s once the inline or refreshed token expires.
 *
 * Checks the suggest bundle in the highest zc_plugins/Seekmodo/v1.3.x tree.
 *
 *     php tests/test_suggest_token_force_refresh.php
 */
declare(strict_types=1);

$base = dirname(__DIR__) . '/zc_plugins/Seekmodo';
$best = null;
$bestKey = [-1, -1, -1];
foreach (glob($base . '/v1.3.*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', basename($dir), $m)) {
        continue;
    }
    $key = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if ($key > $bestKey) {
        $bestKey = $key;
        $best = $dir;
    }
}
if ($best === null) {
    fwrite(STDERR, "No zc_plugins/Seekmodo/v1.3.* directory found\n");
    exit(1);
}

$bundle = $best . '/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js';
$js = is_file($bundle) ? (string) file_get_contents($bundle) : '';

$checks = [
    'bundle exists' => $js !== '',
    'SDK passes forceRefresh to getToken on retry'
        => preg_match('/this\.config\.getToken\(\{\s*forceRefresh(:\s*\w+)?\s*\}\)/', $js) === 1,
    'no getToken() call without the retry flag'
        => strpos($js, 'this.config.getToken()') === false,
    'page token cache is dropped on forced refresh'
        => preg_match('/\.forceRefresh\s*&&\s*\w+\)\s*\{?\s*\w+\s*=\s*null/', $js) === 1,
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo 'test_suggest_token_force_refresh (' . basename($best) . "): {$failed} failure(s).\n";
exit($failed === 0 ? 0 : 1);

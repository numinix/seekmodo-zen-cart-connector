<?php
/**
 * v1.3.47 — CLI storefront-host fallback to HTTPS_SERVER.
 *
 *     php tests/test_cli_host_fallback_v147.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.47/catalog/includes/library/Numinix/Seekmodo/Client.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.47/catalog/includes/functions/numinix_seekmodo_locked_domain.php';

/** @param mixed $expected @param mixed $actual */
function assertEq(string $label, $expected, $actual, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $msg = "  FAIL {$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
    $errors[] = $msg;
    echo $msg . "\n";
}

unset($_SERVER['HTTP_HOST']);

// Prefer HTTPS_CATALOG_SERVER when present.
if (!defined('HTTPS_CATALOG_SERVER')) {
    define('HTTPS_CATALOG_SERVER', 'https://catalog.example.test/');
}
if (!defined('HTTPS_SERVER')) {
    define('HTTPS_SERVER', 'https://www.example.com/');
}

assertEq(
    'prefers_https_catalog_server',
    'catalog.example.test',
    \Numinix\Seekmodo\Client::storefrontHost(),
    $errors,
    $passed
);
assertEq(
    'current_host_matches_client',
    \Numinix\Seekmodo\Client::storefrontHost(),
    numinix_seekmodo_current_host(),
    $errors,
    $passed
);

echo "\n";
if ($errors === []) {
    echo "test_cli_host_fallback_v147: {$passed} assertion(s) passed.\n";
    echo "(HTTPS_SERVER-only branch covered by subprocess below)\n";
    $php = PHP_BINARY;
    $clientPath = str_replace('\\', '/', realpath(__DIR__ . '/../zc_plugins/Seekmodo/v1.3.47/catalog/includes/library/Numinix/Seekmodo/Client.php'));
    $script = <<<PHP
<?php
require_once '{$clientPath}';
unset(\$_SERVER['HTTP_HOST']);
define('HTTPS_SERVER', 'https://www.redlinestands.com');
\$host = \\Numinix\\Seekmodo\\Client::storefrontHost();
echo \$host === 'www.redlinestands.com' ? "PASS https_server_only\\n" : "FAIL got={\$host}\\n";
exit(\$host === 'www.redlinestands.com' ? 0 : 1);
PHP;
    $tmp = tempnam(sys_get_temp_dir(), 'smhost');
    file_put_contents($tmp, $script);
    $out = [];
    $code = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);
    echo implode("\n", $out) . "\n";
    exit($code === 0 ? 0 : 1);
}
echo "test_cli_host_fallback_v147: " . count($errors) . " failure(s), {$passed} passed.\n";
foreach ($errors as $e) {
    echo "{$e}\n";
}
exit(1);

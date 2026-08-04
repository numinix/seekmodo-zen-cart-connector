<?php
/**
 * Regression test for the v1.0.17 SKU exact-match boost helper
 * (`_numinix_seekmodo_apply_sku_boost`). Pins:
 *
 *   1. SKU-shape queries (`STD-1234`, `EZ-LK99`, `12345`,
 *      `RLS_99`, `XYZ.001`) set `prioritize_exact_match=true` on
 *      the payload.
 *
 *   2. Multi-word natural-language queries (`automotive
 *      rotisserie`, `motorcycle stand`) leave the payload
 *      unchanged.
 *
 *   3. Empty / whitespace-only queries are no-ops.
 *
 *   4. Disabled master switch (`NUMINIX_SEEKMODO_SKU_BOOST_ENABLED
 *      = 'false'`) produces a no-op even on a SKU-shape query.
 *
 *   5. A custom override regex (`NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX
 *      = '/^[0-9]+$/'`) only matches its own shape — letters no
 *      longer trigger the boost.
 *
 *   6. A malformed override regex is treated as a no-op (storefront
 *      never 500s on a typo'd operator override).
 *
 *   7. Caller-set `prioritize_exact_match` is preserved (the helper
 *      doesn't second-guess explicit caller intent — useful for a
 *      future per-call override that wants to disable the boost
 *      on a specific request).
 *
 * Self-contained — no PHPUnit. PHP constants can't be redefined
 * in-process, so we shell out to a per-case child PHP process the
 * same way `tests/test_client_modes.php` does. Runs as:
 *
 *     php tests/Sprint17SkuBoostTest.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

function s17sku_assert_eq($expected, $actual, string $label, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $line = "{$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
    $errors[] = $line;
    echo "  FAIL {$line}\n";
}

/**
 * Spawn a child PHP process to evaluate the helper with the given
 * (constants × payload-seed × keyword) tuple.
 *
 * @param array{enabled?:string|null, regex?:string|null, payload:array<string,mixed>, keyword:string} $payload
 * @return array{prioritize_exact_match?:mixed}
 */
function s17sku_runCaseInChild(array $payload): array
{
    $b64 = base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__FILE__) . ' --child=' . escapeshellarg($b64) . ' 2>&1';
    $out = shell_exec($cmd);
    if (!is_string($out) || $out === '') {
        return ['__error' => '(empty child output)'];
    }
    $lines = explode("\n", trim($out));
    $line = trim((string)end($lines));
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        return ['__error' => "bad child output: {$out}"];
    }
    return $decoded;
}

// Child mode — invoked by the parent for each case.
$argvIdx = $_SERVER['argv'] ?? $argv ?? [];
foreach ($argvIdx as $arg) {
    if (!is_string($arg) || !str_starts_with($arg, '--child=')) {
        continue;
    }
    $b64 = substr($arg, 8);
    $payload = json_decode((string)base64_decode($b64), true);
    if (!is_array($payload)) {
        fwrite(STDERR, "child: bad payload\n");
        exit(2);
    }

    if (array_key_exists('enabled', $payload) && $payload['enabled'] !== null) {
        define('NUMINIX_SEEKMODO_SKU_BOOST_ENABLED', (string)$payload['enabled']);
    }
    if (array_key_exists('regex', $payload) && $payload['regex'] !== null) {
        define('NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX', (string)$payload['regex']);
    }

    // Stub Zen Cart / connector helpers the search-lib file references
    // at function-definition time (none today — every reference is
    // inside a function body — but defensive in case future helpers
    // grow eager dependencies).
    if (!function_exists('numinix_seekmodo_register_filter_mapping')) {
        // Loaded transitively below; no stub needed.
    }

    require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.44/catalog/includes/functions/numinix_seekmodo_search_lib.php';

    $inputPayload = (array)($payload['payload'] ?? []);
    $keyword = (string)($payload['keyword'] ?? '');
    $mode = (string)($payload['mode'] ?? 'boost');
    if ($mode === 'exact') {
        $result = _numinix_seekmodo_apply_exact_sku_filter($inputPayload, $keyword);
    } elseif ($mode === 'looks') {
        $result = [
            'looks' => _numinix_seekmodo_looks_like_exact_part_token(trim($keyword)),
        ];
    } else {
        $result = _numinix_seekmodo_apply_sku_boost($inputPayload, $keyword);
    }

    echo json_encode($result) . "\n";
    exit(0);
}

echo "== v1.0.17 SKU exact-match boost regression suite ==\n\n";

// ---------------------------------------------------------------------
// Case 1 — Default constants (helper enabled, default regex).
//          SKU-shape queries trigger; natural-language queries don't.
// ---------------------------------------------------------------------
echo "Case 1: default constants (enabled, default regex)\n";

$skuQueries = [
    'STD-1234',           // dash-separated alphanumeric
    'EZ-LK99',            // AKS-style EZ#
    'RLS_99',             // underscore separator
    'XYZ.001',            // dot separator
    '12345',              // pure numeric SKU
    'A1',                 // 2-char minimum
    str_repeat('A', 32),  // 32-char maximum
];
foreach ($skuQueries as $q) {
    $r = s17sku_runCaseInChild([
        'payload' => ['q' => $q],
        'keyword' => $q,
    ]);
    s17sku_assert_eq(
        true,
        $r['prioritize_exact_match'] ?? null,
        sprintf('SKU "%s" sets prioritize_exact_match', $q),
        $errors,
        $passed
    );
}

$naturalQueries = [
    'automotive rotisserie',
    'motorcycle stand',
    'red key',                 // two short words; space disqualifies
    'wide stand for harley',
    'STD/1234',                // slash isn't in default regex
    'A',                       // single char (regex requires 2+)
    str_repeat('A', 33),       // 33 chars (regex caps at 32)
];
foreach ($naturalQueries as $q) {
    $r = s17sku_runCaseInChild([
        'payload' => ['q' => $q],
        'keyword' => $q,
    ]);
    s17sku_assert_eq(
        false,
        array_key_exists('prioritize_exact_match', $r),
        sprintf('natural-lang "%s" leaves payload unchanged', $q),
        $errors,
        $passed
    );
}

// Empty / whitespace queries — no-op
foreach (['', '   ', "\t\n"] as $q) {
    $r = s17sku_runCaseInChild([
        'payload' => ['q' => '*'],
        'keyword' => $q,
    ]);
    s17sku_assert_eq(
        false,
        array_key_exists('prioritize_exact_match', $r),
        sprintf('empty/whitespace keyword (%s) is a no-op', json_encode($q)),
        $errors,
        $passed
    );
}

// ---------------------------------------------------------------------
// Case 2 — Master switch off. SKU-shape query is a no-op.
// ---------------------------------------------------------------------
echo "\nCase 2: master switch disabled\n";

$r = s17sku_runCaseInChild([
    'enabled' => 'false',
    'payload' => ['q' => 'STD-1234'],
    'keyword' => 'STD-1234',
]);
s17sku_assert_eq(
    false,
    array_key_exists('prioritize_exact_match', $r),
    'disabled master switch is a no-op even for SKU-shape',
    $errors,
    $passed
);

// ---------------------------------------------------------------------
// Case 3 — Custom override regex. Only its own shape triggers.
// ---------------------------------------------------------------------
echo "\nCase 3: custom override regex (numeric only)\n";

$r = s17sku_runCaseInChild([
    'regex' => '/^[0-9]+$/',
    'payload' => ['q' => '12345'],
    'keyword' => '12345',
]);
s17sku_assert_eq(
    true,
    $r['prioritize_exact_match'] ?? null,
    'custom regex matches its own shape',
    $errors,
    $passed
);

$r = s17sku_runCaseInChild([
    'regex' => '/^[0-9]+$/',
    'payload' => ['q' => 'STD-1234'],
    'keyword' => 'STD-1234',
]);
s17sku_assert_eq(
    false,
    array_key_exists('prioritize_exact_match', $r),
    'custom numeric-only regex does NOT match alphanumeric-with-dash',
    $errors,
    $passed
);

// ---------------------------------------------------------------------
// Case 4 — Malformed override regex must be a no-op (no PHP warning,
//          no 500 — never break the storefront on a bad regex).
// ---------------------------------------------------------------------
echo "\nCase 4: malformed override regex\n";

$r = s17sku_runCaseInChild([
    // Unbalanced parens — preg_match returns false for a malformed
    // pattern; the helper treats false as "no match" → no-op.
    'regex' => '/[unbalanced/',
    'payload' => ['q' => 'STD-1234'],
    'keyword' => 'STD-1234',
]);
s17sku_assert_eq(
    false,
    array_key_exists('prioritize_exact_match', $r),
    'malformed regex is a no-op (storefront never 500s)',
    $errors,
    $passed
);

// ---------------------------------------------------------------------
// Case 5 — Caller-set prioritize_exact_match is preserved (so a
//          future per-call override that explicitly wants the boost
//          OFF can pass `false` and the helper won't silently flip
//          it back on).
// ---------------------------------------------------------------------
echo "\nCase 5: caller-set prioritize_exact_match preserved\n";

$r = s17sku_runCaseInChild([
    'payload' => ['q' => 'STD-1234', 'prioritize_exact_match' => false],
    'keyword' => 'STD-1234',
]);
s17sku_assert_eq(
    false,
    $r['prioritize_exact_match'] ?? null,
    'caller-set false is preserved',
    $errors,
    $passed
);

$r = s17sku_runCaseInChild([
    'payload' => ['q' => 'STD-1234', 'prioritize_exact_match' => true],
    'keyword' => 'STD-1234',
]);
s17sku_assert_eq(
    true,
    $r['prioritize_exact_match'] ?? null,
    'caller-set true is preserved',
    $errors,
    $passed
);

// ---------------------------------------------------------------------
// Case 6 — v1.3.44 exact model/sku filter (AKS parity / 4-6340-20).
// ---------------------------------------------------------------------
echo "\nCase 6: exact SKU filter for hyphenated part tokens\n";

foreach (['4-6340-20', 'GM-933', '72147-TZ6-A71', 'EZ-LK99', '4-6340'] as $q) {
    $r = s17sku_runCaseInChild([
        'mode' => 'looks',
        'payload' => [],
        'keyword' => $q,
    ]);
    s17sku_assert_eq(
        true,
        $r['looks'] ?? null,
        sprintf('looksLikeExactPartToken("%s")', $q),
        $errors,
        $passed
    );
}

$r = s17sku_runCaseInChild([
    'mode' => 'looks',
    'payload' => [],
    'keyword' => 'brk-sk-for',
]);
s17sku_assert_eq(
    false,
    $r['looks'] ?? null,
    'letter-only model prefix is NOT exact-filtered',
    $errors,
    $passed
);

$r = s17sku_runCaseInChild([
    'mode' => 'exact',
    'payload' => ['q' => '4-6340-20'],
    'keyword' => '4-6340-20',
]);
s17sku_assert_eq(
    '(model:=`4-6340-20` || sku:=`4-6340-20`)',
    $r['filter_by'] ?? null,
    '4-6340-20 sets exact model/sku filter',
    $errors,
    $passed
);
s17sku_assert_eq(
    true,
    $r['prioritize_exact_match'] ?? null,
    '4-6340-20 exact filter sets prioritize_exact_match',
    $errors,
    $passed
);
s17sku_assert_eq(
    '4-6340-20',
    $r['q'] ?? null,
    '4-6340-20 keeps keyword as q (no rewrite to *)',
    $errors,
    $passed
);

$r = s17sku_runCaseInChild([
    'mode' => 'exact',
    'payload' => ['q' => 'brk-sk-for'],
    'keyword' => 'brk-sk-for',
]);
s17sku_assert_eq(
    false,
    array_key_exists('filter_by', $r),
    'letter-only prefix leaves filter_by unset',
    $errors,
    $passed
);

// ---------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------
echo "\n== Summary ==\n";
echo "  passed: $passed\n";
echo "  failed: " . count($errors) . "\n";
if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  $e\n";
    }
    exit(1);
}

exit(0);

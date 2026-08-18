<?php
/**
 * Contract: seekmodo_nocache bypasses cache READ but still WRITE-THROUGH.
 *
 * Operators use ?seekmodo_nocache=1 after a ranking fix; wasting that
 * gateway call by skipping the put left View All on the stale TTL entry.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$searchLib = $root . '/zc_plugins/Seekmodo/v1.3.74/catalog/includes/functions/numinix_seekmodo_search_lib.php';
$typeaheadLib = $root . '/zc_plugins/Seekmodo/v1.3.74/catalog/includes/functions/numinix_seekmodo_typeahead_lib.php';

$fail = 0;
function assertTrue(string $label, bool $ok): void
{
    global $fail;
    if ($ok) {
        echo "OK  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

assertTrue('search_lib exists', is_file($searchLib));
assertTrue('typeahead_lib exists', is_file($typeaheadLib));

$search = (string) file_get_contents($searchLib);
$typeahead = (string) file_get_contents($typeaheadLib);

assertTrue(
    'search defines $cacheEnabled separate from read bypass',
    str_contains($search, '$cacheEnabled = ($mode === \'enforce\');')
        && str_contains($search, '$useCacheRead = $cacheEnabled && !$cacheBypass;')
);
assertTrue(
    'search write-through gated on $cacheEnabled (not $useCacheRead)',
    str_contains($search, 'if ($cacheEnabled && $cacheKey !== null && is_array($normalized)')
);
assertTrue(
    'search records bypass-write status',
    str_contains($search, "'bypass-write'")
);
assertTrue(
    'typeahead write-through gated on $cacheEnabled',
    str_contains($typeahead, 'if ($cacheEnabled && $cacheKey !== null && is_array($result)')
);
assertTrue(
    'typeahead records bypass-write status',
    str_contains($typeahead, "'bypass-write'")
);

exit($fail === 0 ? 0 : 1);

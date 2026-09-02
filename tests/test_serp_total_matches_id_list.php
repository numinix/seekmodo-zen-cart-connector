<?php
/**
 * SERP pagination total must not understate the listing ID list.
 *
 * When Typesense `found` (or a legacy es_products bag) is lower than
 * count($productIds) used for ORDER BY FIELD … IN (…), splitPageResults
 * still LIMITs against the longer SQL and infinite-scroll themes report
 * "40 of 34 results".
 *
 *     php tests/test_serp_total_matches_id_list.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

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

/**
 * Mirrors NuminixSeekmodoObserver total resolution after stock partition.
 *
 * @param int[] $productIds
 */
function seekmodo_resolve_serp_pagination_total(?int $envelopeTotal, array $productIds): int
{
    $idCount = count($productIds);
    $total = $envelopeTotal !== null ? $envelopeTotal : $idCount;
    if ($idCount > $total) {
        $total = $idCount;
    }
    return $total;
}

assertEq('uses envelope when aligned', 34, seekmodo_resolve_serp_pagination_total(34, range(1, 34)), $errors, $passed);
assertEq('raises to id list when found understates', 54, seekmodo_resolve_serp_pagination_total(34, range(1, 54)), $errors, $passed);
assertEq('falls back to id count', 10, seekmodo_resolve_serp_pagination_total(null, range(1, 10)), $errors, $passed);
assertEq('keeps found when larger than fetched ids', 100, seekmodo_resolve_serp_pagination_total(100, range(1, 50)), $errors, $passed);

if ($errors !== []) {
    echo "test_serp_total_matches_id_list: " . count($errors) . " failure(s), {$passed} passed.\n";
    exit(1);
}
echo "test_serp_total_matches_id_list: {$passed} assertion(s) passed.\n";
exit(0);

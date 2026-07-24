<?php
/**
 * Unit tests for RecommendationsCascade (no Zen Cart bootstrap).
 */

declare(strict_types=1);

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.30/catalog/includes/library/Numinix/Seekmodo/RecommendationsCascade.php';

use Numinix\Seekmodo\RecommendationsCascade;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

$recommendFn = static function (string $algo, array $params): ?array {
    $anchor = (string) ($params['anchor_doc_id'] ?? '');
    if ($algo === 'also_bought') {
        return [
            'recommendations' => [
                ['doc_id' => '10', 'score' => 1.0, 'source' => 'co_purchase'],
                ['doc_id' => '11', 'score' => 0.9, 'source' => 'co_purchase'],
                ['doc_id' => '12', 'score' => 0.8, 'source' => 'co_purchase'],
            ],
        ];
    }
    if ($algo === 'related') {
        return [
            'recommendations' => [
                ['doc_id' => '10', 'score' => 1.0, 'source' => 'co_view'], // duplicate of bought
                ['doc_id' => '20', 'score' => 0.9, 'source' => 'co_view'],
            ],
        ];
    }
    if ($algo === 'also_viewed') {
        return ['recommendations' => [['doc_id' => '21', 'score' => 0.5, 'source' => 'session']]];
    }
    if ($algo === 'bundle.suggest') {
        return [
            'bundle' => [
                'score' => 0.4,
                'picks' => [['doc_id' => '22', 'title' => 'Bundle item']],
            ],
        ];
    }
    return ['recommendations' => []];
};

$hydrate = static function (array $rows): array {
    return $rows;
};

$peers = [
    ['doc_id' => '10', 'score' => 0.0, 'source' => 'category_peer'], // claimed by bought
    ['doc_id' => '30', 'score' => 0.0, 'source' => 'category_peer'],
    ['doc_id' => '31', 'score' => 0.0, 'source' => 'category_peer'],
];

$pdp = RecommendationsCascade::runPdp('1', 2, [], $recommendFn, $hydrate, $peers);
assert_true(count($pdp['bought']) === 2, 'pdp bought capped at limit');
assert_true($pdp['bought'][0]['doc_id'] === '10', 'pdp bought first');
assert_true($pdp['related'][0]['doc_id'] === '20', 'pdp related skips duplicate 10');
assert_true($pdp['popular'][0]['doc_id'] === '30', 'pdp popular skips claimed');
$ids = array_merge(
    array_column($pdp['bought'], 'doc_id'),
    array_column($pdp['related'], 'doc_id'),
    array_column($pdp['popular'], 'doc_id')
);
assert_true(count($ids) === count(array_unique($ids)), 'pdp cross-section unique');

$cart = RecommendationsCascade::runCart(
    ['1', '2'],
    ['1', '2', '3'],
    5,
    [],
    $recommendFn,
    $hydrate,
    [['doc_id' => '3', 'score' => 0.0, 'source' => 'category_peer'], ['doc_id' => '40', 'score' => 0.0, 'source' => 'category_peer']]
);
$cartIds = array_column($cart['recommendations'], 'doc_id');
assert_true(!in_array('1', $cartIds, true), 'cart excludes anchor 1');
assert_true(!in_array('2', $cartIds, true), 'cart excludes anchor 2');
assert_true(!in_array('3', $cartIds, true), 'cart excludes in-basket 3');
assert_true(in_array('10', $cartIds, true) || in_array('40', $cartIds, true), 'cart returns candidates');

echo "All cascade tests passed.\n";

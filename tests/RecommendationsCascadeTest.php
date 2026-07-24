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

$kept = RecommendationsCascade::rejectColdStartSources([
    ['doc_id' => '1', 'source' => 'co_purchase'],
    ['doc_id' => '2', 'source' => 'lexical'],
    ['doc_id' => '3', 'source' => 'category_peer'],
    ['doc_id' => '4', 'source' => 'pairs'],
    ['doc_id' => '5', 'source' => 'trending'],
]);
assert_true(array_column($kept, 'doc_id') === ['1', '4'], 'rejectColdStartSources');

$supportFn = static function (string $algo, array $params): ?array {
    if ($algo !== 'also_bought') {
        return ['recommendations' => []];
    }
    $anchor = (string) ($params['anchor_doc_id'] ?? '');
    if ($anchor === '1') {
        return ['recommendations' => [
            ['doc_id' => '99', 'score' => 0.5, 'source' => 'co_purchase'],
            ['doc_id' => '88', 'score' => 0.99, 'source' => 'co_purchase'],
        ]];
    }
    if ($anchor === '2' || $anchor === '3') {
        return ['recommendations' => [
            ['doc_id' => '99', 'score' => 0.5, 'source' => 'co_purchase'],
        ]];
    }
    return ['recommendations' => []];
};
$supportCart = RecommendationsCascade::runCart(['1', '2', '3'], ['1', '2', '3'], 2, [], $supportFn, $hydrate);
assert_true(array_column($supportCart['recommendations'], 'doc_id') === ['99', '88'], 'support_count ranks multi-anchor first');
assert_true(($supportCart['recommendations'][0]['support_count'] ?? 0) === 3, 'support_count=3');
assert_true(($supportCart['recommendations'][1]['support_count'] ?? 0) === 1, 'support_count=1');

$seenAnchors = [];
$capFn = static function (string $algo, array $params) use (&$seenAnchors): ?array {
    $anchor = (string) ($params['anchor_doc_id'] ?? '');
    if ($anchor !== '' && !in_array($anchor, $seenAnchors, true)) {
        $seenAnchors[] = $anchor;
    }
    return ['recommendations' => []];
};
$twelve = array_map('strval', range(1, 12));
$capCart = RecommendationsCascade::runCart($twelve, $twelve, 5, [], $capFn, $hydrate);
assert_true(count($capCart['meta']['anchors']) === 10, 'meta.anchors capped at 10');
assert_true(($capCart['meta']['anchor_cap'] ?? 0) === 10, 'meta.anchor_cap=10');
assert_true(count(array_unique($seenAnchors)) === 10, 'only 10 anchors queried');

$edgeFn = static function (string $algo, array $params): ?array {
    if ($algo === 'related') {
        return ['recommendations' => [['doc_id' => '50', 'score' => 1.0, 'source' => 'co_view']]];
    }
    return ['recommendations' => []];
};
$emptyCart = RecommendationsCascade::runCart([], [], 5, [], $edgeFn, $hydrate);
assert_true($emptyCart['recommendations'] === [], 'empty cart works');
$singleCart = RecommendationsCascade::runCart(['7'], ['7'], 5, [], $edgeFn, $hydrate);
assert_true(array_column($singleCart['recommendations'], 'doc_id') === ['50'], 'single-line cart works');
assert_true($singleCart['meta']['anchors'] === ['7'], 'single anchor in meta');

echo "All cascade tests passed.\n";

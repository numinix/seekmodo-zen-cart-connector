<?php
/**
 * PDP / cart recommendation cascades — AKS RecommendationsAdapter parity.
 *
 * @see seekmodo.com/docs/connectors/recommendations-pdp-cart.md
 */

declare(strict_types=1);

namespace Numinix\Seekmodo;

final class RecommendationsCascade
{
    public const CART_ANCHOR_CAP = 10;

    /** @var list<string> */
    private const COLD_START_SOURCES = ['lexical', 'category_peer', 'trending'];

    /** @var array<string,int> */
    private const SOURCE_PRIORITY = [
        'co_purchase'   => 100,
        'pairs'         => 90,
        'co_view'       => 90,
        'same_session'  => 80,
        'session'       => 80,
        'bundle'        => 70,
        'lexical'       => 40,
        'category_peer' => 20,
        'trending'      => 10,
    ];

    /**
     * @param array<string,mixed> $basePayload session/ua/ip/shopper_context
     * @param callable(string,array<string,mixed>):(?array) $recommendFn
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $hydrateFn
     * @return array{
     *   bought: list<array<string,mixed>>,
     *   related: list<array<string,mixed>>,
     *   popular: list<array<string,mixed>>,
     *   meta: array<string,mixed>
     * }
     */
    public static function runPdp(
        string $anchorDocId,
        int $limit,
        array $basePayload,
        callable $recommendFn,
        callable $hydrateFn,
        array $categoryPeerRows = []
    ): array {
        $limit = max(1, min(24, $limit));
        $claimed = [];
        if ($anchorDocId !== '' && ctype_digit($anchorDocId) && (int) $anchorDocId > 0) {
            $claimed[(int) $anchorDocId] = true;
        }

        $bought = self::takeUnclaimed(
            self::rejectColdStartSources(
                $hydrateFn(self::extractRows($recommendFn('also_bought', self::anchorPayload($basePayload, $anchorDocId, $limit * 2))))
            ),
            $claimed,
            $limit
        );

        $related = self::takeUnclaimed(
            $hydrateFn(self::extractRows($recommendFn('related', self::anchorPayload($basePayload, $anchorDocId, $limit * 2)))),
            $claimed,
            $limit
        );
        if (count($related) < $limit) {
            $related = array_merge($related, self::takeUnclaimed(
                $hydrateFn(self::extractRows($recommendFn('also_viewed', self::anchorPayload($basePayload, $anchorDocId, $limit * 2)))),
                $claimed,
                $limit - count($related)
            ));
        }
        if (count($related) < $limit && $anchorDocId !== '') {
            $related = array_merge($related, self::takeUnclaimed(
                $hydrateFn(self::extractRows($recommendFn('bundle.suggest', self::bundlePayload($basePayload, $anchorDocId)))),
                $claimed,
                $limit - count($related)
            ));
        }

        $popular = self::takeUnclaimed($categoryPeerRows, $claimed, $limit);
        if (count($related) < $limit && $categoryPeerRows !== []) {
            $related = array_merge($related, self::takeUnclaimed(
                $categoryPeerRows,
                $claimed,
                $limit - count($related)
            ));
        }

        return [
            'bought'  => $bought,
            'related' => $related,
            'popular' => $popular,
            'meta'    => [
                'limit'         => $limit,
                'bought_count'  => count($bought),
                'related_count' => count($related),
                'popular_count' => count($popular),
            ],
        ];
    }

    /**
     * @param list<string> $anchorDocIds
     * @param list<string> $excludeDocIds
     * @param array<string,mixed> $basePayload
     * @param callable(string,array<string,mixed>):(?array) $recommendFn
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $hydrateFn
     * @param list<array<string,mixed>> $categoryPeerRows
     * @return array{recommendations:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public static function runCart(
        array $anchorDocIds,
        array $excludeDocIds,
        int $limit,
        array $basePayload,
        callable $recommendFn,
        callable $hydrateFn,
        array $categoryPeerRows = []
    ): array {
        $limit = max(1, min(24, $limit));
        $anchors = [];
        foreach ($anchorDocIds as $raw) {
            $id = (string) $raw;
            if ($id === '' || !ctype_digit($id) || (int) $id <= 0) {
                continue;
            }
            if (!in_array($id, $anchors, true)) {
                $anchors[] = $id;
            }
        }

        $claimed = [];
        foreach (array_merge($anchors, $excludeDocIds) as $raw) {
            $id = (string) $raw;
            if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                $claimed[(int) $id] = true;
            }
        }

        $anchorSlice = array_slice($anchors, 0, self::CART_ANCHOR_CAP);
        $bag = [];

        foreach ($anchorSlice as $anchor) {
            foreach (['also_bought', 'related', 'also_viewed'] as $algo) {
                $rows = $hydrateFn(self::extractRows($recommendFn(
                    $algo,
                    self::anchorPayload($basePayload, $anchor, $limit * 2)
                )));
                if ($algo === 'also_bought') {
                    $rows = self::rejectColdStartSources($rows);
                }
                self::absorbCartHits($bag, $rows, $anchor, $claimed);
            }
            $bundleRows = $hydrateFn(self::extractRows($recommendFn(
                'bundle.suggest',
                self::bundlePayload($basePayload, $anchor)
            )));
            self::absorbCartHits($bag, $bundleRows, $anchor, $claimed);
        }

        self::absorbCartHits($bag, $categoryPeerRows, null, $claimed);

        $out = self::rankCartCandidates($bag, $limit, $claimed);

        return [
            'recommendations' => $out,
            'meta'            => [
                'limit'      => $limit,
                'anchors'    => $anchorSlice,
                'count'      => count($out),
                'anchor_cap' => self::CART_ANCHOR_CAP,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function rejectColdStartSources(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = strtolower(trim((string) ($row['source'] ?? '')));
            if ($source !== '' && in_array($source, self::COLD_START_SOURCES, true)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,array{row:array<string,mixed>,support_anchors:array<string,bool>,best_score:float,best_source_priority:int}> $bag
     * @param list<array<string,mixed>> $rows
     * @param array<int,bool> $claimed
     */
    private static function absorbCartHits(array &$bag, array $rows, ?string $anchorId, array $claimed): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = self::productIdFromRow($row);
            if ($pid === null || isset($claimed[$pid])) {
                continue;
            }
            $key = (string) $pid;
            $pri = self::sourcePriority((string) ($row['source'] ?? ''));
            $score = isset($row['score']) ? (float) $row['score'] : 0.0;
            if (!isset($bag[$key])) {
                $support = [];
                if ($anchorId !== null) {
                    $support[$anchorId] = true;
                }
                $bag[$key] = [
                    'row'                  => $row,
                    'support_anchors'      => $support,
                    'best_score'           => $score,
                    'best_source_priority' => $pri,
                ];
                continue;
            }
            if ($anchorId !== null) {
                $bag[$key]['support_anchors'][$anchorId] = true;
            }
            if ($score > $bag[$key]['best_score']) {
                $bag[$key]['best_score'] = $score;
            }
            if ($pri > $bag[$key]['best_source_priority']) {
                $bag[$key]['best_source_priority'] = $pri;
                $bag[$key]['row']['source'] = (string) ($row['source'] ?? '');
            }
        }
    }

    /**
     * @param array<string,array{row:array<string,mixed>,support_anchors:array<string,bool>,best_score:float,best_source_priority:int}> $bag
     * @param array<int,bool> $claimed
     * @return list<array<string,mixed>>
     */
    private static function rankCartCandidates(array $bag, int $limit, array &$claimed): array
    {
        $candidates = array_values($bag);
        usort($candidates, static function (array $a, array $b): int {
            $supportDiff = count($b['support_anchors']) - count($a['support_anchors']);
            if ($supportDiff !== 0) {
                return $supportDiff;
            }
            if ($b['best_score'] !== $a['best_score']) {
                return $b['best_score'] <=> $a['best_score'];
            }
            return $b['best_source_priority'] <=> $a['best_source_priority'];
        });

        $out = [];
        foreach ($candidates as $cand) {
            if (count($out) >= $limit) {
                break;
            }
            $pid = self::productIdFromRow($cand['row']);
            if ($pid === null || isset($claimed[$pid])) {
                continue;
            }
            $claimed[$pid] = true;
            $row = $cand['row'];
            $row['score'] = $cand['best_score'];
            $support = count($cand['support_anchors']);
            if ($support > 0) {
                $row['support_count'] = $support;
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function sourcePriority(string $source): int
    {
        $key = strtolower(trim($source));
        return self::SOURCE_PRIORITY[$key] ?? 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function productIdFromRow(array $row): ?int
    {
        $docId = (string) ($row['doc_id'] ?? '');
        if ($docId === '' || !ctype_digit($docId)) {
            return null;
        }
        $pid = (int) $docId;
        return $pid > 0 ? $pid : null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,bool> $claimed
     * @return list<array<string,mixed>>
     */
    public static function takeUnclaimed(array $rows, array &$claimed, int $limit): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $docId = (string) ($row['doc_id'] ?? '');
            if ($docId === '' || !ctype_digit($docId)) {
                continue;
            }
            $pid = (int) $docId;
            if ($pid <= 0 || isset($claimed[$pid])) {
                continue;
            }
            $claimed[$pid] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $resp
     * @return list<array<string,mixed>>
     */
    public static function extractRows(?array $resp): array
    {
        if (!is_array($resp)) {
            return [];
        }
        $rows = [];
        if (isset($resp['bundle']) && is_array($resp['bundle']) && isset($resp['bundle']['picks']) && is_array($resp['bundle']['picks'])) {
            foreach ($resp['bundle']['picks'] as $pick) {
                if (!is_array($pick)) {
                    continue;
                }
                $docId = (string) ($pick['doc_id'] ?? '');
                if ($docId === '') {
                    continue;
                }
                $rows[] = [
                    'doc_id' => $docId,
                    'score'  => isset($resp['bundle']['score']) ? (float) $resp['bundle']['score'] : 0.0,
                    'source' => 'bundle',
                    'name'   => (string) ($pick['title'] ?? ''),
                    'brand'  => (string) ($pick['brand'] ?? ''),
                    'price'  => isset($pick['price']) ? (float) $pick['price'] : null,
                    'image'  => isset($pick['image_url']) ? (string) $pick['image_url'] : '',
                ];
            }
            return $rows;
        }

        $raw = $resp['recommendations'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $docIdR = (string) ($r['doc_id'] ?? '');
            if ($docIdR === '') {
                continue;
            }
            $row = [
                'doc_id' => $docIdR,
                'score'  => isset($r['score']) ? (float) $r['score'] : 0.0,
                'source' => (string) ($r['source'] ?? ''),
            ];
            if (isset($resp['hits']) && is_array($resp['hits']) && isset($resp['hits'][$docIdR]) && is_array($resp['hits'][$docIdR])) {
                $doc = $resp['hits'][$docIdR];
                $row['name'] = (string) ($doc['name'] ?? '');
                $row['model'] = (string) ($doc['model'] ?? '');
                if (isset($doc['price'])) {
                    $row['price'] = (float) $doc['price'];
                }
                if (isset($doc['image'])) {
                    $row['image'] = (string) $doc['image'];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function anchorPayload(array $base, string $anchorDocId, int $limit): array
    {
        $payload = $base;
        $payload['limit'] = $limit;
        $payload['anchor_doc_id'] = $anchorDocId;
        return $payload;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function bundlePayload(array $base, string $anchorDocId): array
    {
        $payload = self::anchorPayload($base, $anchorDocId, 5);
        $payload['bundle_size'] = 3;
        return $payload;
    }
}

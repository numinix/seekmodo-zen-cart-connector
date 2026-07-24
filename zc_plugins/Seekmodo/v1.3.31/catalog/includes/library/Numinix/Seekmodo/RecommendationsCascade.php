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
            $hydrateFn(self::extractRows($recommendFn('also_bought', self::anchorPayload($basePayload, $anchorDocId, $limit * 2)))),
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

        $out = [];
        $anchorSlice = array_slice($anchors, 0, 3);
        foreach (['also_bought', 'related', 'also_viewed'] as $algo) {
            foreach ($anchorSlice as $anchor) {
                if (count($out) >= $limit) {
                    break 2;
                }
                $out = array_merge($out, self::takeUnclaimed(
                    $hydrateFn(self::extractRows($recommendFn($algo, self::anchorPayload($basePayload, $anchor, $limit * 2)))),
                    $claimed,
                    $limit - count($out)
                ));
            }
        }
        foreach ($anchorSlice as $anchor) {
            if (count($out) >= $limit) {
                break;
            }
            $out = array_merge($out, self::takeUnclaimed(
                $hydrateFn(self::extractRows($recommendFn('bundle.suggest', self::bundlePayload($basePayload, $anchor)))),
                $claimed,
                $limit - count($out)
            ));
        }

        if (count($out) < $limit && $categoryPeerRows !== []) {
            $out = array_merge($out, self::takeUnclaimed(
                $categoryPeerRows,
                $claimed,
                $limit - count($out)
            ));
        }

        return [
            'recommendations' => $out,
            'meta'            => [
                'limit'   => $limit,
                'anchors' => $anchors,
                'count'   => count($out),
            ],
        ];
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

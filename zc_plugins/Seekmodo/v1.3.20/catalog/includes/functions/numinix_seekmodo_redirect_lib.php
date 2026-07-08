<?php
/**
 * Keyword merchandising redirect resolver for Zen Cart server 302 hooks.
 *
 * Resolution order (v1.3.9+):
 *   1. Cached `redirect_rules` slice from tenant.snapshot (5-min TTL)
 *   2. Gateway `resolve_redirect` tool (fallback)
 *
 * Merchandising redirect wins over auto category redirect in
 * NuminixSeekmodoObserver::onAdvancedSearchStart.
 */

if (!function_exists('numinix_seekmodo_resolve_merchandising_redirect')) {
    /**
     * @return string|null Absolute or site-relative target URL
     */
    function numinix_seekmodo_resolve_merchandising_redirect(string $q): ?string
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }

        $rules = numinix_seekmodo_redirect_rules_cached();
        if ($rules !== []) {
            $match = numinix_seekmodo_match_redirect_rules($rules, $q, 'search');
            if ($match !== null) {
                return (string) ($match['target_url'] ?? '');
            }
        }

        if (!class_exists('\Numinix\Seekmodo\Client')) {
            return null;
        }
        try {
            $client = new \Numinix\Seekmodo\Client();
            if (!$client->isEnabled()) {
                return null;
            }
            $resp = $client->callTool('resolve_redirect', ['q' => $q]);
            if (!is_array($resp)) {
                return null;
            }
            $redirect = $resp['redirect'] ?? null;
            if (!is_array($redirect)) {
                return null;
            }
            $url = trim((string) ($redirect['target_url'] ?? ''));
            return $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            if (function_exists('numinix_seekmodo_log_observation')) {
                numinix_seekmodo_log_observation('redirect_resolver_fail', [
                    'msg' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }
}

if (!function_exists('numinix_seekmodo_redirect_rules_cached')) {
    /**
     * @return list<array<string,mixed>>
     */
    function numinix_seekmodo_redirect_rules_cached(): array
    {
        $cacheKey = 'sm_redirect_rules_v1';
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $hit = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }

        $rules = [];
        if (class_exists('\Numinix\Seekmodo\RemoteConfig')) {
            try {
                $cfg = (new \Numinix\Seekmodo\RemoteConfig())->pull();
                if (is_array($cfg['redirect_rules'] ?? null)) {
                    $rules = $cfg['redirect_rules'];
                }
            } catch (\Throwable $e) {
                // fail open
            }
        }

        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($cacheKey, $rules, 300);
        }
        return $rules;
    }
}

if (!function_exists('numinix_seekmodo_match_redirect_rules')) {
    /**
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    function numinix_seekmodo_match_redirect_rules(array $rules, string $q, string $mode = 'search'): ?array
    {
        $qNorm = numinix_seekmodo_redirect_normalize($q);
        if ($qNorm === '') {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach ($rules as $row) {
            if (!is_array($row)) {
                continue;
            }
            $target = trim((string) ($row['target_url'] ?? ''));
            if ($target === '') {
                continue;
            }
            $matchMode = (string) ($row['match_mode'] ?? 'exact');
            $terms = $row['terms'] ?? [];
            if (!is_array($terms)) {
                $terms = [];
            }
            $scopeValue = trim((string) ($row['scope_value'] ?? ''));
            if ($scopeValue !== '' && !in_array($scopeValue, $terms, true)) {
                $terms[] = $scopeValue;
            }
            $prio = (int) ($row['priority'] ?? 100);
            foreach ($terms as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }
                $score = numinix_seekmodo_redirect_match_score($qNorm, $term, $matchMode, $mode);
                if ($score < 0) {
                    continue;
                }
                if ($best === null || $score > $bestScore || ($score === $bestScore && $prio > $bestPrio)) {
                    $bestScore = $score;
                    $bestPrio = $prio;
                    $best = [
                        'rule_id' => (string) ($row['rule_id'] ?? ''),
                        'target_url' => $target,
                        'matched_term' => $term,
                        'label' => $row['label'] ?? $term,
                    ];
                }
            }
        }
        return $best;
    }
}

if (!function_exists('numinix_seekmodo_redirect_normalize')) {
    function numinix_seekmodo_redirect_normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }
}

if (!function_exists('numinix_seekmodo_redirect_term_matches')) {
    function numinix_seekmodo_redirect_term_matches(
        string $qNorm,
        string $term,
        string $matchMode,
        string $mode
    ): bool {
        return numinix_seekmodo_redirect_match_score($qNorm, $term, $matchMode, $mode) >= 0;
    }
}

if (!function_exists('numinix_seekmodo_redirect_match_score')) {
    function numinix_seekmodo_redirect_match_score(
        string $qNorm,
        string $term,
        string $matchMode,
        string $mode
    ): int {
        $termNorm = numinix_seekmodo_redirect_normalize($term);
        if ($termNorm === '') {
            return -1;
        }
        if ($matchMode === 'contains') {
            if ($mode === 'search') {
                if (stripos($qNorm, $termNorm) !== false
                    || stripos($termNorm, $qNorm) !== false) {
                    return 3000 + strlen($termNorm);
                }
                return -1;
            }
            if (stripos($termNorm, $qNorm) === 0
                || stripos($qNorm, $termNorm) !== false) {
                return 3000 + strlen($termNorm);
            }
            return -1;
        }
        if ($qNorm === $termNorm) {
            return 10000 + strlen($termNorm);
        }
        if ($mode === 'suggest' && strncmp($termNorm, $qNorm, strlen($qNorm)) === 0) {
            return 5000 + strlen($termNorm);
        }
        return -1;
    }
}

if (!function_exists('numinix_seekmodo_issue_redirect')) {
    function numinix_seekmodo_issue_redirect(string $url): void
    {
        if ($url === '') {
            return;
        }
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        exit;
    }
}

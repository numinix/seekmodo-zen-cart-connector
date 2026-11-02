<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Product + category discovery via Seekmodo.
 *
 * Product resolution order:
 *  1. Paired Numinix Seekmodo Client (storefront connector)
 *  2. Direct HMAC POST to /v1/search using chatbot or connector config
 *
 * Categories are resolved in parallel (merchandising redirect, auto
 * category redirect, /v1/suggest) so chat can link a matching category
 * page as well as individual products — same shape as storefront suggest.
 */
final class SeekmodoProductSearch
{
    /**
     * @return array{
     *   available: bool,
     *   source?: string,
     *   query?: string,
     *   found?: int,
     *   products?: list<array<string, mixed>>,
     *   categories?: list<array{name: string, url: string, breadcrumb?: string, source?: string}>,
     *   error?: string,
     *   hint?: string
     * }
     */
    public function search(string $query, int $limit = 8, ?string $filterBy = null): array
    {
        $query = trim($query);
        $filterBy = $filterBy !== null ? trim($filterBy) : null;
        if ($query === '') {
            if ($filterBy === null || $filterBy === '') {
                return ['available' => true, 'error' => 'query is required'];
            }
            // Facet-only browse (e.g. "which products allow photo uploads")
            // — Typesense convention for match-all + filter_by.
            $query = '*';
        }

        $limit = max(1, min(20, $limit));
        $params = [
            'q' => $query,
            'per_page' => $limit,
            'page' => 1,
            'parse_nl' => true,
            // Keep ranked products even when a keyword redirect exists;
            // categories are resolved separately below.
            'skip_merchandising_redirect' => true,
            'session_id' => $this->shopperSessionId(),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'openai-gpt-chatbot'), 0, 512),
            'ip' => $this->shopperIp(),
            'referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 512),
        ];
        if ($filterBy !== null && $filterBy !== '') {
            $params['filter_by'] = $filterBy;
        }

        $response = $this->searchViaConnector($params);
        $source = 'seekmodo_connector';
        // Dig/staging often pair a connector tenant (e.g. kip-dev) whose
        // catalog is empty while the chatbot HMAC tenant (kip) still has
        // the full index. Fall through to HMAC when the connector answers
        // but returns zero hits.
        if ($this->responseHasHits($response)) {
            // keep connector response
        } else {
            $hmac = $this->hmacPost('/v1/search', $params);
            if ($hmac !== null) {
                $response = $hmac;
                $source = 'seekmodo_hmac';
            } elseif ($response === null) {
                return [
                    'available' => false,
                    'error' => 'seekmodo_unavailable',
                    'hint' => 'Seekmodo search unavailable. Call mcp_product_search once as a fallback. If that is also empty, ask ONE clarifying question (interests, product type, budget) and search again — never invent non-catalog gift ideas.',
                    'query' => $query,
                ];
            }
        }

        if ($response === null) {
            return [
                'available' => false,
                'error' => 'seekmodo_unavailable',
                'hint' => 'Seekmodo search unavailable. Call mcp_product_search once as a fallback. If that is also empty, ask ONE clarifying question (interests, product type, budget) and search again — never invent non-catalog gift ideas.',
                'query' => $query,
            ];
        }

        $hits = $response['results']['hits'] ?? [];
        if (!is_array($hits)) {
            $hits = [];
        }

        $products = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $doc = $hit['document'] ?? $hit;
            if (!is_array($doc)) {
                continue;
            }
            $normalized = $this->normalizeDocument($doc);
            if ($normalized !== null) {
                $products[] = $normalized;
            }
            if (count($products) >= $limit) {
                break;
            }
        }

        $categories = $query === '*'
            ? []
            : $this->resolveCategories($query, min(5, $limit));

        $found = (int) ($response['results']['found'] ?? count($products));
        $result = [
            'available' => true,
            'source' => $source,
            'query' => $query,
            'found' => $found,
            'products' => $products,
            'categories' => $categories,
        ];
        if (($products === [] || $found === 0) && $categories === []) {
            $result['empty'] = true;
            $result['hint'] = 'No catalog matches for this query. Ask ONE clarifying question (interests/hobbies, product type, budget, personalisation preference) and call seekmodo_product_search again with a tighter query. Do not invent non-catalog gift ideas.';
        } elseif ($categories !== []) {
            $result['hint'] = 'Present matching categories first (Markdown links to category pages), then up to 5–8 product links. Broad occasion/age/gift queries should lead with the best category.';
        }
        return $result;
    }

    /**
     * Matching category pages for the query (redirects + suggest categories).
     *
     * @return list<array{name: string, url: string, breadcrumb?: string, source?: string}>
     */
    private function resolveCategories(string $query, int $limit = 5): array
    {
        $limit = max(1, min(8, $limit));
        $out = [];
        $seen = [];

        $add = function (string $name, string $url, string $source, string $breadcrumb = '') use (&$out, &$seen, $limit): void {
            if (count($out) >= $limit) {
                return;
            }
            $name = trim($name);
            $url = $this->rewriteStorefrontUrl(trim($url));
            if ($name === '' || $url === '') {
                return;
            }
            $key = strtolower(rtrim(parse_url($url, PHP_URL_PATH) ?: $url, '/'));
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $row = [
                'name' => $name,
                'url' => $url,
                'source' => $source,
            ];
            if ($breadcrumb !== '') {
                $row['breadcrumb'] = $breadcrumb;
            }
            $out[] = $row;
        };

        // 1) Merchandising keyword redirect (cached snapshot or gateway).
        $merch = $this->resolveMerchandisingCategory($query);
        if ($merch !== null) {
            $add(
                (string) ($merch['name'] ?? ''),
                (string) ($merch['url'] ?? ''),
                'merchandising_redirect'
            );
        }

        // 2) Auto category redirect (Zen Cart leaf-name similarity).
        if (function_exists('numinix_seekmodo_resolve_category_redirect')) {
            try {
                $catUrl = numinix_seekmodo_resolve_category_redirect($query, [
                    'record_observation' => false,
                ]);
            } catch (\Throwable $e) {
                $catUrl = null;
            }
            if (is_string($catUrl) && $catUrl !== '') {
                $add(
                    $this->labelFromCategoryUrl($catUrl, $query),
                    $catUrl,
                    'category_redirect'
                );
            }
        }

        // 3) Suggest categories + "Go to" redirects (ids → storefront URLs).
        $suggest = $this->suggestViaConnector([
            'q' => $query,
            'limit' => $limit,
            'include_products' => false,
            'session_id' => $this->shopperSessionId(),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'openai-gpt-chatbot'), 0, 512),
            'ip' => $this->shopperIp(),
        ]);
        if ($suggest === null || empty($suggest['categories'])) {
            $hmacSuggest = $this->hmacPost('/v1/suggest', [
                'q' => $query,
                'limit' => $limit,
                'include_products' => false,
                'session_id' => $this->shopperSessionId(),
                'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'openai-gpt-chatbot'), 0, 512),
                'ip' => $this->shopperIp(),
            ]);
            if (is_array($hmacSuggest)) {
                $suggest = $hmacSuggest;
            }
        }
        if (is_array($suggest)) {
            $cats = $suggest['categories'] ?? [];
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    if (!is_array($cat)) {
                        continue;
                    }
                    $name = trim((string) ($cat['name'] ?? ''));
                    $breadcrumb = trim((string) ($cat['breadcrumb'] ?? ''));
                    $url = '';
                    $cid = (int) ($cat['id'] ?? $cat['category_id'] ?? 0);
                    if ($cid > 0 && function_exists('_numinix_seekmodo_category_url')) {
                        $built = _numinix_seekmodo_category_url($cid);
                        if (is_string($built) && $built !== '') {
                            $url = $built;
                        }
                    }
                    if ($url === '' && !empty($cat['url'])) {
                        $url = (string) $cat['url'];
                    }
                    if ($name === '' || $url === '') {
                        continue;
                    }
                    $add($name, $url, 'suggest', $breadcrumb);
                }
            }
            $redirects = $suggest['redirects'] ?? [];
            if (is_array($redirects)) {
                foreach ($redirects as $redir) {
                    if (!is_array($redir)) {
                        continue;
                    }
                    $label = trim((string) ($redir['label'] ?? $redir['matched_term'] ?? ''));
                    $target = trim((string) ($redir['target_url'] ?? $redir['url'] ?? ''));
                    if ($label === '' || $target === '') {
                        continue;
                    }
                    $add($label, $target, 'suggest_redirect');
                }
            }
            $topRedirect = $suggest['redirect'] ?? null;
            if (is_array($topRedirect)) {
                $label = trim((string) ($topRedirect['label'] ?? $topRedirect['matched_term'] ?? ''));
                $target = trim((string) ($topRedirect['target_url'] ?? ''));
                if ($label !== '' && $target !== '') {
                    $add($label, $target, 'suggest_redirect');
                }
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, url: string}|null
     */
    private function resolveMerchandisingCategory(string $query): ?array
    {
        if (
            function_exists('numinix_seekmodo_redirect_rules_cached')
            && function_exists('numinix_seekmodo_match_redirect_rules')
        ) {
            try {
                $rules = numinix_seekmodo_redirect_rules_cached();
                $match = is_array($rules) && $rules !== []
                    ? numinix_seekmodo_match_redirect_rules($rules, $query, 'search')
                    : null;
                if (is_array($match)) {
                    $url = trim((string) ($match['target_url'] ?? ''));
                    $name = trim((string) ($match['label'] ?? $match['matched_term'] ?? ''));
                    if ($url !== '' && $name !== '') {
                        return ['name' => $name, 'url' => $url];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to gateway
            }
        }

        $resp = null;
        $this->ensureSeekmodoAutoload();
        if (class_exists('\\Numinix\\Seekmodo\\Client')) {
            try {
                $client = \Numinix\Seekmodo\Client::fromConfiguration();
                if ($client !== null && $client->isEnabled()) {
                    $resp = $client->callTool('resolve_redirect', ['q' => $query]);
                }
            } catch (\Throwable $e) {
                $resp = null;
            }
        }
        if ($resp === null) {
            $resp = $this->hmacPost('/v1/resolve_redirect', ['q' => $query]);
        }
        if (!is_array($resp)) {
            return null;
        }
        $redirect = $resp['redirect'] ?? null;
        if (!is_array($redirect)) {
            return null;
        }
        $url = trim((string) ($redirect['target_url'] ?? ''));
        $name = trim((string) ($redirect['label'] ?? $redirect['matched_term'] ?? ''));
        if ($url === '') {
            return null;
        }
        if ($name === '') {
            $name = $this->labelFromCategoryUrl($url, $query);
        }
        return ['name' => $name, 'url' => $url];
    }

    private function labelFromCategoryUrl(string $url, string $fallback): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $base = basename($path);
        // Zen Cart SEO: 70th-birthday-gifts-c-11_255.html → "70th birthday gifts"
        if (preg_match('/^(.+)-c-\d+(?:_\d+)*/i', $base, $m)) {
            $slug = str_replace('-', ' ', $m[1]);
            $slug = trim(preg_replace('/\s+/', ' ', $slug) ?? $slug);
            if ($slug !== '') {
                return ucwords($slug);
            }
        }
        $fallback = trim($fallback);
        return $fallback !== '' ? $fallback : 'Matching category';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function suggestViaConnector(array $params): ?array
    {
        $this->ensureSeekmodoAutoload();
        if (!class_exists('\\Numinix\\Seekmodo\\Client')) {
            return null;
        }

        /** @var \Numinix\Seekmodo\Client|null $client */
        $client = \Numinix\Seekmodo\Client::fromConfiguration();
        if ($client === null || !$client->isEnabled()) {
            return null;
        }
        if (!method_exists($client, 'suggest')) {
            return null;
        }
        try {
            $resp = $client->suggest($params);
            return is_array($resp) ? $resp : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function responseHasHits(?array $response): bool
    {
        if ($response === null) {
            return false;
        }
        $found = (int) ($response['results']['found'] ?? 0);
        if ($found > 0) {
            return true;
        }
        $hits = $response['results']['hits'] ?? null;
        return is_array($hits) && $hits !== [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function searchViaConnector(array $params): ?array
    {
        $this->ensureSeekmodoAutoload();
        if (!class_exists('\\Numinix\\Seekmodo\\Client')) {
            return null;
        }

        /** @var \Numinix\Seekmodo\Client|null $client */
        $client = \Numinix\Seekmodo\Client::fromConfiguration();
        if ($client === null || !$client->isEnabled()) {
            return null;
        }

        return $client->search($params);
    }

    /**
     * HMAC POST for a Seekmodo gateway tool. Null when this store is
     * not paired or the gateway does not answer.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function gatewayPost(string $path, array $params): ?array
    {
        return $this->hmacPost($path, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function hmacPost(string $path, array $params): ?array
    {
        $cfg = $this->resolveHmacConfig();
        if ($cfg === null) {
            return null;
        }

        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        $url = rtrim($cfg['url'], '/') . $path;
        $sig = hash_hmac('sha256', $body, $cfg['secret']);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Seekmodo-Tenant: ' . $cfg['tenant'],
            'X-Seekmodo-Signature: ' . $sig,
            'X-Seekmodo-Timestamp: ' . time(),
        ];
        // Prefer an allowed storefront host. Sending an unpaired host
        // (e.g. a dig hostname against a production-locked tenant) returns HTTP 403.
        $host = $this->seekmodoStorefrontHost($cfg['tenant']);
        if ($host !== '') {
            $headers[] = 'X-Seekmodo-Storefront-Host: ' . $host;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{url: string, tenant: string, secret: string}|null
     */
    private function resolveHmacConfig(): ?array
    {
        $url = $this->firstCfg(['SEEKMODO_AI_CHATBOT_SEEKMODO_URL', 'NUMINIX_SEEKMODO_URL']);
        $tenant = $this->firstCfg(['SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_ID', 'NUMINIX_SEEKMODO_TENANT_ID']);
        $secret = $this->firstCfg(['SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET', 'NUMINIX_SEEKMODO_SHARED_SECRET']);

        if ($url === '' || $tenant === '' || $secret === '') {
            return null;
        }

        return [
            'url' => $url,
            'tenant' => $tenant,
            'secret' => $secret,
        ];
    }

    /**
     * First non-empty defined constant. An installer default of '' must
     * not hide the connector's NUMINIX_SEEKMODO_* credentials.
     *
     * @param list<string> $keys
     */
    private function firstCfg(array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->cfg($key);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function cfg(string $key, string $default = ''): string
    {
        if (defined($key)) {
            $value = constant($key);
            if (is_string($value) || is_numeric($value)) {
                return trim((string) $value);
            }
        }
        return $default;
    }

    /**
     * When the chat endpoint runs on PHP 8.1, Seekmodo's catalog init may
     * never have registered (connector usually boots on PHP 7.4). Load the
     * Client autoloader from the installed plugin tree when present.
     */
    private function ensureSeekmodoAutoload(): void
    {
        if (class_exists('\\Numinix\\Seekmodo\\Client', false)) {
            return;
        }

        $catalog = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : getcwd() . '/';
        $base = rtrim($catalog, '/\\') . '/zc_plugins/Seekmodo';
        if (!is_dir($base)) {
            return;
        }

        $versions = glob($base . '/v*', GLOB_ONLYDIR) ?: [];
        rsort($versions, SORT_NATURAL);
        foreach ($versions as $versionDir) {
            $init = $versionDir . '/catalog/includes/init_includes/init_numinix_seekmodo.php';
            if (is_file($init)) {
                require_once $init;
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>|null
     */
    private function normalizeDocument(array $doc): ?array
    {
        $id = (string) ($doc['id'] ?? $doc['products_id'] ?? '');
        $name = trim((string) ($doc['name'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }

        $description = trim(strip_tags((string) ($doc['description'] ?? '')));
        if (strlen($description) > 280) {
            $description = substr($description, 0, 277) . '...';
        }

        $price = $doc['price'] ?? null;

        $normalized = [
            'products_id' => (int) $id,
            'name' => $name,
            'model' => (string) ($doc['model'] ?? $doc['sku'] ?? ''),
            'price' => is_numeric($price) ? (float) $price : $price,
            'currency' => (string) ($doc['currency'] ?? 'GBP'),
            'in_stock' => !empty($doc['in_stock']) || !empty($doc['purchasable']),
            'url' => $this->rewriteStorefrontUrl((string) ($doc['url'] ?? '')),
            'image_url' => $this->normalizeImageUrl((string) ($doc['image_url'] ?? '')),
            'description' => $description,
        ];
        // Only surface the facet when the index actually carries it (older
        // docs pre-dating this field) so the model doesn't read a stale
        // "false" as a confirmed no.
        if (array_key_exists('supports_photo_upload', $doc)) {
            $normalized['supports_photo_upload'] = !empty($doc['supports_photo_upload']);
        }
        if (array_key_exists('supports_text_personalisation', $doc)) {
            $normalized['supports_text_personalisation'] = !empty($doc['supports_text_personalisation']);
        }
        if (!empty($doc['personalisation_engine'])) {
            $normalized['personalisation_engine'] = (string) $doc['personalisation_engine'];
        }
        return $normalized;
    }

    private function rewriteStorefrontUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $server = defined('HTTPS_SERVER') && HTTPS_SERVER !== ''
            ? (string) HTTPS_SERVER
            : (defined('HTTP_SERVER') ? (string) HTTP_SERVER : '');
        if ($server === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }

        return rtrim($server, '/') . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Rewrite to the current storefront host and drop tracking/query junk so
     * the model embeds a clean image URL (broken files still 404 — the widget
     * hides those client-side).
     */
    private function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return '';
        }
        $path = (string) $parts['path'];
        // Only keep obvious image paths; skip empty / root placeholders.
        if ($path === '/' || $path === '') {
            return '';
        }
        if (!preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $path)) {
            // Still allow CDN paths without an extension, but strip query.
            $clean = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
                . (isset($parts['host']) ? $parts['host'] : '')
                . $path;
            return $this->rewriteStorefrontUrl($clean !== '' ? $clean : $url);
        }
        $clean = (isset($parts['scheme']) ? $parts['scheme'] . '://' : 'https://')
            . (isset($parts['host']) ? $parts['host'] : '')
            . $path;
        return $this->rewriteStorefrontUrl($clean);
    }

    private function seekmodoStorefrontHost(string $tenant): string
    {
        $configured = $this->cfg('SEEKMODO_AI_CHATBOT_SEEKMODO_STOREFRONT_HOST');
        if ($configured !== '') {
            return strtolower($configured);
        }
        if (!empty($GLOBALS['seekmodo_ai_chatbot_seekmodo_storefront_host'])
            && is_string($GLOBALS['seekmodo_ai_chatbot_seekmodo_storefront_host'])) {
            return strtolower(trim($GLOBALS['seekmodo_ai_chatbot_seekmodo_storefront_host']));
        }

        // Optional map: tenant_id => canonical host the Seekmodo gateway expects.
        // Admin JSON config, or site $GLOBALS['seekmodo_ai_chatbot_seekmodo_tenant_hosts'].
        $map = null;
        $mapRaw = $this->cfg('SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_HOST_MAP');
        if ($mapRaw !== '') {
            $decoded = json_decode($mapRaw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }
        if ($map === null
            && !empty($GLOBALS['seekmodo_ai_chatbot_seekmodo_tenant_hosts'])
            && is_array($GLOBALS['seekmodo_ai_chatbot_seekmodo_tenant_hosts'])) {
            $map = $GLOBALS['seekmodo_ai_chatbot_seekmodo_tenant_hosts'];
        }
        if (is_array($map) && $tenant !== '' && !empty($map[$tenant]) && is_string($map[$tenant])) {
            return strtolower(trim($map[$tenant]));
        }

        // Omit header when unsure — gateway accepts unsigned host as empty.
        return '';
    }

    private function storefrontHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') {
            return strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
        }
        if (defined('HTTP_SERVER')) {
            $parts = parse_url((string) HTTP_SERVER);
            if (!empty($parts['host'])) {
                return strtolower((string) $parts['host']);
            }
        }
        return '';
    }

    private function shopperSessionId(): string
    {
        if (!empty($_SESSION['securityToken'])) {
            return substr('chat_' . md5((string) $_SESSION['securityToken']), 0, 64);
        }
        if (session_id() !== '') {
            return substr('chat_' . session_id(), 0, 64);
        }
        return 'chatbot_' . substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'x')), 0, 16);
    }

    private function shopperIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = (string) $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $raw = trim(explode(',', $raw)[0]);
            }
            if (filter_var($raw, FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }
        return '127.0.0.1';
    }
}

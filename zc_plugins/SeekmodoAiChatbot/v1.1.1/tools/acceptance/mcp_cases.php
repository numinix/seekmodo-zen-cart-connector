<?php
/**
 * MCP Direct Acceptance Tests (A suite).
 *
 * Usage:
 *   php tools/acceptance/mcp_cases.php --base https://example.com --mcp-token TOKEN
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @return list<array{id: string, passes: bool, detail: string}> */
function run_mcp_cases(string $baseUrl, string $chatbotToken, string $operatorToken): array
{
    $mcpUrl = rtrim($baseUrl, '/') . '/numinix_mcp.php';
    $results = [];

    echo "\n--- MCP Direct Cases (A) ---\n";

    // M01: Health check
    $id = 'M01';
    try {
        $resp = mcp_call($mcpUrl, 'store.ping', [], null);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $ok = ($structured['ok'] ?? false) === true && !empty($structured['store_name']);
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'ok=true store=' . ($structured['store_name'] ?? '') : 'missing ok/store_name'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M02: Find product by name
    $id = 'M02';
    try {
        $resp = mcp_call($mcpUrl, 'product.search', ['name' => 'Wine Glass'], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $products = $structured['products'] ?? [];
        $ok = count($products) > 0 && !empty($products[0]['products_id']);
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'found ' . count($products) . ' products' : 'no products'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
        if ($ok) {
            $GLOBALS['_test_fixture_products_id'] = (int) $products[0]['products_id'];
        }
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    $fixturePid = $GLOBALS['_test_fixture_products_id'] ?? 0;

    // M03: Product detail
    $id = 'M03';
    try {
        if ($fixturePid <= 0) {
            throw new \RuntimeException('No fixture product ID from M02');
        }
        $resp = mcp_call($mcpUrl, 'product.get', ['products_id' => $fixturePid], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $product = $structured['product'] ?? [];
        $hasName = !empty($product['products_name']);
        $hasUrl = !empty($product['canonical_url']);
        $hasDesc = !empty($product['description_plain']);
        $isActive = ($product['products_status'] ?? 0) == 1;
        $ok = $hasName && $hasUrl && $hasDesc && $isActive;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'name+url+desc+active' : ($hasName ? 'missing url/desc/active' : 'missing name')];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M04: Resolve from URL
    $id = 'M04';
    try {
        $productUrl = '';
        foreach ($results as $r) {
            if ($r['id'] === 'M03') {
                // Re-fetch to get URL
                break;
            }
        }
        // Fetch product again just to get URL
        $resp = mcp_call($mcpUrl, 'product.get', ['products_id' => $fixturePid], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $productUrl = $structured['product']['canonical_url'] ?? '';
        $mcpResp = mcp_call($mcpUrl, 'product.resolve', ['url' => $productUrl], $chatbotToken);
        $s = $mcpResp['result']['structuredContent'] ?? [];
        if (empty($s) && isset($mcpResp['result']['content'][0]['text'])) {
            $s = json_decode($mcpResp['result']['content'][0]['text'], true) ?: [];
        }
        $resolvedPid = (int) ($s['products_id'] ?? 0);
        $ok = $resolvedPid === $fixturePid;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'matches ' . $fixturePid : "got {$resolvedPid}, expected {$fixturePid}"];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M05: Resolve by SKU/model
    // We need model from M03
    $id = 'M05';
    try {
        $resp = mcp_call($mcpUrl, 'product.get', ['products_id' => $fixturePid], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $model = $structured['product']['products_model'] ?? '';
        if ($model === '') {
            $results[] = ['id' => $id, 'passes' => false, 'detail' => 'no model on fixture product'];
            fail($id, 'no model on fixture product');
        } else {
            $mcpResp = mcp_call($mcpUrl, 'product.resolve', ['model' => $model], $chatbotToken);
            $s = $mcpResp['result']['structuredContent'] ?? [];
            if (empty($s) && isset($mcpResp['result']['content'][0]['text'])) {
                $s = json_decode($mcpResp['result']['content'][0]['text'], true) ?: [];
            }
            $resolvedPid = (int) ($s['products_id'] ?? 0);
            $ok = $resolvedPid === $fixturePid;
            $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? "model={$model} matches" : "got {$resolvedPid}, expected {$fixturePid}"];
            $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
        }
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M06: Chatbot token reads products (all M02-M05 already used chatbot token, so this passes if those passed)
    $id = 'M06';
    $m02p = $results[array_search('M02', array_column($results, 'id'))]['passes'] ?? false;
    $m03p = $results[array_search('M03', array_column($results, 'id'))]['passes'] ?? false;
    $m04p = $results[array_search('M04', array_column($results, 'id'))]['passes'] ?? false;
    $m05p = $results[array_search('M05', array_column($results, 'id'))]['passes'] ?? false;
    $ok = $m02p && $m03p && $m04p && $m05p;
    $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'M02-M05 all passed with chatbot token' : 'some M02-M05 failed'];
    $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);

    // M07: Chatbot token CANNOT mutate
    $id = 'M07';
    try {
        $resp = mcp_call($mcpUrl, 'product.status.set', ['products_id' => $fixturePid ?: 1, 'status' => 1], $chatbotToken);
        $hasError = isset($resp['error']) || isset($resp['result']['isError']);
        if ($hasError) {
            $results[] = ['id' => $id, 'passes' => true, 'detail' => 'mutation denied (403)'];
            pass($id, 'denied');
        } else {
            $results[] = ['id' => $id, 'passes' => false, 'detail' => 'mutation unexpectedly succeeded'];
            fail($id, 'mutation unexpectedly succeeded');
        }
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception thrown: ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M08: Order lookup with chatbot token
    $id = 'M08';
    try {
        $resp = mcp_call($mcpUrl, 'order.get', ['orders_id' => 1374052], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $ok = !empty($structured['orders_id'] ?? $structured['order']['orders_id'] ?? 0);
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'order found' : 'order not found'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M09: Shipping upgrade options require ownership (wrong customers_id → error)
    $id = 'M09';
    try {
        $resp = mcp_call($mcpUrl, 'order.shipping.upgrade.options', [
            'orders_id' => 1374052,
            'customers_id' => 999999999,
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $isError = !empty($resp['result']['isError']) || !empty($structured['error']);
        $ok = $isError;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'ownership rejected' : 'unexpected success'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M10: Shipping upgrade create is chatbot-allowlisted (wrong owner still fails ownership, not scope)
    $id = 'M10';
    try {
        $resp = mcp_call($mcpUrl, 'order.shipping.upgrade.create', [
            'orders_id' => 1374052,
            'customers_id' => 999999999,
            'module' => 'flat',
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $rpcError = $resp['error']['message'] ?? '';
        $toolError = (string) ($structured['error'] ?? '');
        $ok = !empty($resp['result']['isError'])
            || $toolError !== ''
            || in_array($rpcError, ['readonly_scope', 'insufficient_scope'], true) === false;
        // Pass if we did not get a hard scope denial — ownership/not-found is the expected path.
        $scopeDenied = $rpcError === 'readonly_scope' || $toolError === 'readonly_scope';
        $ok = !$scopeDenied && ($toolError !== '' || !empty($resp['result']['isError']) || isset($resp['error']));
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? ('create blocked: ' . ($toolError ?: $rpcError ?: 'error')) : 'scope incorrectly denied or unexpected success'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M11: tools/list includes shipping upgrade tools for chatbot token
    $id = 'M11';
    try {
        $resp = mcp_send($mcpUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ], $chatbotToken);
        $tools = $resp['result']['tools'] ?? [];
        $names = [];
        foreach ($tools as $tool) {
            if (is_array($tool) && isset($tool['name'])) {
                $names[] = $tool['name'];
            }
        }
        $needed = [
            'order.shipping.upgrade.options',
            'order.shipping.upgrade.create',
            'order.shipping.upgrade.status',
            'order.shipping.upgrade.cancel',
        ];
        $missing = array_values(array_diff($needed, $names));
        $ok = $missing === [];
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'all upgrade tools listed' : ('missing: ' . implode(',', $missing))];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M12: kb.topics.list includes order-cancel
    $id = 'M12';
    try {
        $resp = mcp_call($mcpUrl, 'kb.topics.list', [], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $topics = $structured['topics'] ?? [];
        $ids = [];
        foreach ($topics as $topic) {
            if (is_array($topic) && isset($topic['id'])) {
                $ids[] = (string) $topic['id'];
            }
        }
        $ok = in_array('order-cancel', $ids, true)
            && in_array('shipping', $ids, true)
            && in_array('returns', $ids, true);
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'order-cancel+shipping+returns present' : ('topics: ' . implode(',', $ids))];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M13: Address-change preview rejects wrong owner
    $id = 'M13';
    try {
        $resp = mcp_call($mcpUrl, 'order.address.change.preview', [
            'orders_id' => 1374052,
            'customers_id' => 999999999,
            'delivery' => [
                'name' => 'Test User',
                'street_address' => '1 Wrong Owner Lane',
                'city' => 'London',
                'postcode' => 'SW1A 1AA',
                'country' => 'United Kingdom',
            ],
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $isError = !empty($resp['result']['isError']) || !empty($structured['error']) || isset($resp['error']);
        $ok = $isError;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'ownership rejected' : 'unexpected success'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M14: Confirmation resend rejects wrong owner
    $id = 'M14';
    try {
        $resp = mcp_call($mcpUrl, 'order.confirmation.resend', [
            'orders_id' => 1374052,
            'customers_id' => 999999999,
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $isError = !empty($resp['result']['isError']) || !empty($structured['error']) || isset($resp['error']);
        $ok = $isError;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'ownership rejected' : 'unexpected success'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M15: Password reset unknown email returns generic ok (anti-enumeration)
    $id = 'M15';
    try {
        $resp = mcp_call($mcpUrl, 'customer.password.reset', [
            'email' => 'nobody-does-not-exist-' . time() . '@example.invalid',
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $msg = strtolower((string) ($structured['message'] ?? ''));
        $ok = !empty($structured['ok']) && str_contains($msg, 'if an account exists');
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'generic ok message' : json_encode($structured)];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => false, 'detail' => $e->getMessage()];
        fail($id, $e->getMessage());
    }

    // M16: customer.password.temp is not chatbot-callable (admin-only)
    $id = 'M16';
    try {
        $resp = mcp_call($mcpUrl, 'customer.password.temp', [
            'customers_id' => 1,
            'temp_password' => 'x',
            'confirm_token' => 'x',
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $isError = !empty($resp['result']['isError']) || !empty($structured['error']) || isset($resp['error']);
        $ok = $isError;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'temp password blocked for chatbot' : 'unexpected success'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    // M17: support.return.create rejects wrong owner
    $id = 'M17';
    try {
        $resp = mcp_call($mcpUrl, 'support.return.create', [
            'orders_id' => 1374052,
            'customers_id' => 999999999,
            'reason' => 'acceptance test wrong owner',
        ], $chatbotToken);
        $structured = $resp['result']['structuredContent'] ?? [];
        if (empty($structured) && isset($resp['result']['content'][0]['text'])) {
            $structured = json_decode($resp['result']['content'][0]['text'], true) ?: [];
        }
        $isError = !empty($resp['result']['isError']) || !empty($structured['error']) || isset($resp['error']);
        $hasRefund = isset($structured['refund']) || isset($structured['refund_id']);
        $ok = $isError && !$hasRefund;
        $results[] = ['id' => $id, 'passes' => $ok, 'detail' => $ok ? 'ownership rejected, no refund fields' : 'unexpected'];
        $ok ? pass($id) : fail($id, $results[count($results) - 1]['detail']);
    } catch (\Throwable $e) {
        $results[] = ['id' => $id, 'passes' => true, 'detail' => 'exception (expected): ' . $e->getMessage()];
        pass($id, 'exception (expected)');
    }

    return $results;
}

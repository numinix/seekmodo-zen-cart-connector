<?php
/**
 * Post-chat improvement queue: nightly catch-up + job processing.
 *
 * 1. Enqueues chats that went idle/unresolved (never resolved by the
 *    shopper or an admin) so they still get analyzed.
 * 2. Claims a batch of pending analysis jobs (queued here or right after a
 *    chat resolves/closes — see ChatAgent::enqueuePostChatAnalysis) and runs
 *    PostChatAnalyzer against each transcript.
 * 3. Writes zero or more typed improvement drafts via the Numinix MCP
 *    improvement.propose tool. Nothing here ever changes bot behavior —
 *    every draft sits pending until an admin approves it under
 *    Tools -> Chatbot Improvements.
 *
 * Requires Numinix MCP v1.0.14+ (improvement.* / analysis.job.* tools).
 *
 * Usage:
 *   php post_chat_analysis.php --catalog=/path/to/catalog/root [--host=www.example.com]
 */

declare(strict_types=1);

$catalogDir = '';
$hostOverride = '';
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--catalog=') === 0) {
        $catalogDir = substr($arg, 10);
    }
    if (strpos($arg, '--host=') === 0) {
        $hostOverride = substr($arg, 7);
    }
}
if ($catalogDir === '') {
    if (defined('DIR_FS_CATALOG') && DIR_FS_CATALOG !== '') {
        $catalogDir = DIR_FS_CATALOG;
    } else {
        $catalogDir = realpath(__DIR__ . '/../../../../');
    }
}
if ($catalogDir === false || !is_dir($catalogDir)) {
    fwrite(STDERR, "ERROR: Catalog directory not found: {$catalogDir}\n");
    exit(1);
}
$catalogDir = rtrim(str_replace('\\', '/', (string) $catalogDir), '/');

if ($hostOverride !== '') {
    $httpHost = $hostOverride;
} elseif (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost') {
    $httpHost = (string) $_SERVER['HTTP_HOST'];
} else {
    $httpHost = 'localhost';
    if (preg_match('#/(?:public_html|www)/(?:([^/]+)/)?$#', $catalogDir . '/', $m) && !empty($m[1])) {
        $httpHost = $m[1];
    }
}

$_SERVER['HTTP_HOST'] = $httpHost;
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
chdir($catalogDir);

if (!defined('IS_ADMIN_FLAG')) {
    define('IS_ADMIN_FLAG', false);
}

require_once $catalogDir . '/includes/configure.php';
require_once $catalogDir . '/includes/database_tables.php';
require_once $catalogDir . '/includes/classes/class.base.php';
require_once $catalogDir . '/includes/classes/db/mysql/query_factory.php';
require_once __DIR__ . '/../database_tables.php';

$db = new queryFactory();
if (!$db->connect(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, 'db_link', false)) {
    fwrite(STDERR, "ERROR: Database connection failed for host {$httpHost}\n");
    exit(1);
}
$GLOBALS['db'] = $db;

$configResult = $db->Execute(
    "SELECT configuration_key, configuration_value
       FROM " . TABLE_CONFIGURATION . "
      WHERE configuration_key LIKE 'SEEKMODO_AI_CHATBOT_%'"
);
while (!$configResult->EOF) {
    $key = (string) $configResult->fields['configuration_key'];
    if ($key !== '' && !defined($key)) {
        define($key, (string) $configResult->fields['configuration_value']);
    }
    $configResult->MoveNext();
}

$libBase = __DIR__ . '/../catalog/includes/library/SeekmodoAiChatbot/';
spl_autoload_register(static function (string $class) use ($libBase): void {
    $prefix = 'SeekmodoAiChatbot\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $libBase . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (!defined('SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED') || SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED !== 'true') {
    exit("seekmodo_ai_chatbot_post_chat_analysis: disabled, exiting.\n");
}

$mcpUrl = defined('SEEKMODO_AI_CHATBOT_MCP_URL') ? (string) SEEKMODO_AI_CHATBOT_MCP_URL : '';
$mcpToken = defined('SEEKMODO_AI_CHATBOT_MCP_TOKEN') ? (string) SEEKMODO_AI_CHATBOT_MCP_TOKEN : '';
$apiKey = defined('SEEKMODO_AI_CHATBOT_API_KEY') ? (string) SEEKMODO_AI_CHATBOT_API_KEY : '';
$model = defined('SEEKMODO_AI_CHATBOT_MODEL') ? (string) SEEKMODO_AI_CHATBOT_MODEL : 'gpt-4o-mini';

if ($mcpUrl === '' || $mcpToken === '' || $apiKey === '') {
    exit("seekmodo_ai_chatbot_post_chat_analysis: missing MCP URL/token or LLM API key, exiting.\n");
}

$idleMinutes = defined('SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES') ? (int) SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES : 120;
$minMessages = defined('SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES') ? (int) SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES : 3;
$maxPerNight = defined('SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT') ? (int) SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT : 50;
$batchSize = defined('SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE') ? (int) SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE : 20;

$mcp = new \SeekmodoAiChatbot\McpClient($mcpUrl, $mcpToken, 60);

/**
 * @return list<int>
 */
$seekmodo_ai_chatbot_existing_job_chat_ids = static function () use ($db): array {
    $table = defined('TABLE_NUMINIX_MCP_IMPROVEMENT_JOBS')
        ? TABLE_NUMINIX_MCP_IMPROVEMENT_JOBS
        : ((defined('DB_PREFIX') ? DB_PREFIX : '') . 'numinix_mcp_improvement_jobs');
    $out = [];
    try {
        $exists = $db->Execute("SHOW TABLES LIKE '" . $db->prepareInput($table) . "'");
        if (!$exists || $exists->EOF) {
            return [];
        }
        $res = $db->Execute('SELECT chat_id FROM `' . str_replace('`', '``', $table) . '`');
        while ($res && !$res->EOF) {
            $out[] = (int) $res->fields['chat_id'];
            $res->MoveNext();
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $out;
};

// 1. Nightly catch-up — chats that went idle without ever being resolved,
//    plus recently resolved chats whose resolve-time enqueue may have
//    failed. analysis.job.enqueue is idempotent (unique key on chat_id)
//    and requeues prior 'error' jobs with attempts remaining.
$enqueued = 0;
$excludeChatIds = $seekmodo_ai_chatbot_existing_job_chat_ids();
$idleChats = \SeekmodoAiChatbot\ChatArchive::listIdleUnresolved($idleMinutes, $minMessages, $excludeChatIds, $maxPerNight);
foreach ($idleChats as $row) {
    $chatId = (int) ($row['chat_id'] ?? 0);
    if ($chatId < 1) {
        continue;
    }
    try {
        $mcp->callTool('analysis.job.enqueue', ['chat_id' => $chatId, 'source' => 'cron_idle']);
        $enqueued++;
        $excludeChatIds[] = $chatId;
    } catch (\Throwable $e) {
        fwrite(STDERR, "seekmodo_ai_chatbot_post_chat_analysis: enqueue failed for chat {$chatId}: " . $e->getMessage() . "\n");
    }
}
$resolvedBudget = max(0, $maxPerNight - $enqueued);
if ($resolvedBudget > 0) {
    $resolvedChats = \SeekmodoAiChatbot\ChatArchive::listResolvedForAnalysis($minMessages, $excludeChatIds, $resolvedBudget);
    foreach ($resolvedChats as $row) {
        $chatId = (int) ($row['chat_id'] ?? 0);
        if ($chatId < 1) {
            continue;
        }
        try {
            $mcp->callTool('analysis.job.enqueue', ['chat_id' => $chatId, 'source' => 'cron_catchup']);
            $enqueued++;
        } catch (\Throwable $e) {
            fwrite(STDERR, "seekmodo_ai_chatbot_post_chat_analysis: resolved catch-up enqueue failed for chat {$chatId}: " . $e->getMessage() . "\n");
        }
    }
}

// 2. Known topic ids give the analyzer an allowlist so it never invents a
//    topic id that does not exist — it can only reference these or leave
//    topic_id empty.
$knownTopicIds = [];
try {
    $topicsResult = $mcp->callTool('kb.topics.list', []);
    foreach ((array) ($topicsResult['topics'] ?? []) as $topic) {
        if (is_array($topic) && !empty($topic['id'])) {
            $knownTopicIds[] = (string) $topic['id'];
        }
    }
} catch (\Throwable $e) {
    // Non-fatal — analyzer just proceeds with an empty known-topics list.
}

// 3. Claim and process a batch of pending jobs (queued on resolve or by
//    the catch-up step above).
$claimed = [];
try {
    $claimResult = $mcp->callTool('analysis.job.claim', ['limit' => $batchSize]);
    $claimed = (array) ($claimResult['jobs'] ?? []);
} catch (\Throwable $e) {
    fwrite(STDERR, 'seekmodo_ai_chatbot_post_chat_analysis: claim failed: ' . $e->getMessage() . "\n");
}

$analyzer = new \SeekmodoAiChatbot\PostChatAnalyzer($apiKey, $model);
$processed = 0;
$proposalsTotal = 0;

foreach ($claimed as $job) {
    $jobId = (int) ($job['job_id'] ?? 0);
    $chatId = (int) ($job['chat_id'] ?? 0);
    if ($jobId < 1 || $chatId < 1) {
        continue;
    }

    $chat = \SeekmodoAiChatbot\ChatArchive::getById($chatId);
    if ($chat === null) {
        seekmodo_ai_chatbot_complete_job($mcp, $jobId, 'skipped', 'chat_not_found');
        continue;
    }

    $messages = \SeekmodoAiChatbot\ChatArchive::displayMessages((array) ($chat['messages'] ?? []));
    if (count($messages) < $minMessages) {
        seekmodo_ai_chatbot_complete_job($mcp, $jobId, 'skipped', 'too_few_messages');
        continue;
    }

    $meta = [
        'status' => (string) ($chat['status'] ?? 'unknown'),
        'handed_off' => !empty($chat['handed_off_at']),
        'known_topic_ids' => $knownTopicIds,
    ];

    $result = $analyzer->analyze($messages, $meta);
    if (empty($result['ok'])) {
        seekmodo_ai_chatbot_complete_job($mcp, $jobId, 'error', (string) ($result['error'] ?? 'analyze_failed'));
        continue;
    }

    $sessionId = (string) ($chat['session_id'] ?? '');
    $proposedCount = 0;
    $proposeFailures = 0;
    foreach ((array) ($result['proposals'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        try {
            $mcp->callTool('improvement.propose', [
                'kind' => (string) ($p['kind'] ?? ''),
                'claim' => (string) ($p['claim'] ?? ''),
                'title' => (string) ($p['title'] ?? ''),
                'rationale' => (string) ($p['rationale'] ?? ''),
                'suggested_text' => (string) ($p['suggested_text'] ?? ''),
                'landing_target' => (string) ($p['landing_target'] ?? ''),
                'severity' => (string) ($p['severity'] ?? 'low'),
                'topic_id' => (string) ($p['topic_id'] ?? ''),
                'source_session' => $sessionId,
                'chat_id' => $chatId,
                'source' => 'post_chat',
                'evidence' => ['chat_id' => $chatId],
            ]);
            $proposedCount++;
        } catch (\Throwable $e) {
            $proposeFailures++;
            fwrite(STDERR, "seekmodo_ai_chatbot_post_chat_analysis: propose failed for chat {$chatId}: " . $e->getMessage() . "\n");
        }
    }

    try {
        if ($proposeFailures > 0) {
            // Keep the job retryable — do not mark done when any proposal
            // failed to record, or those findings are permanently lost.
            $mcp->callTool('analysis.job.complete', [
                'job_id' => $jobId,
                'result' => 'error',
                'reason' => 'propose_failed:' . $proposeFailures,
                'proposal_count' => $proposedCount,
            ]);
        } else {
            $mcp->callTool('analysis.job.complete', ['job_id' => $jobId, 'result' => 'done', 'proposal_count' => $proposedCount]);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "seekmodo_ai_chatbot_post_chat_analysis: complete failed for job {$jobId}: " . $e->getMessage() . "\n");
    }

    $processed++;
    $proposalsTotal += $proposedCount;
}

echo "seekmodo_ai_chatbot_post_chat_analysis: enqueued={$enqueued} processed={$processed} proposals={$proposalsTotal}\n";

function seekmodo_ai_chatbot_complete_job(\SeekmodoAiChatbot\McpClient $mcp, int $jobId, string $result, string $reason): void
{
    try {
        $mcp->callTool('analysis.job.complete', ['job_id' => $jobId, 'result' => $result, 'reason' => $reason]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "seekmodo_ai_chatbot_post_chat_analysis: complete({$result}) failed for job {$jobId}: " . $e->getMessage() . "\n");
    }
}

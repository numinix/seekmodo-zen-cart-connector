<?php
/**
 * Acceptance fixtures for EmailReplyExtractor.
 *
 * Usage: php tools/acceptance/email_reply_extractor_cases.php
 */
declare(strict_types=1);

$lib = dirname(__DIR__, 2) . '/catalog/includes/library/SeekmodoAiChatbot/EmailReplyExtractor.php';
require_once $lib;

use SeekmodoAiChatbot\EmailReplyExtractor;

$cases = [
    [
        'name' => 'gmail_on_wrote',
        'input' => "Thanks, that works for me.\n\nOn Mon, Aug 6, 2026 at 10:00 AM Support <help@example.com> wrote:\n> Previous reply\n> More history\n",
        'expect_contains' => 'Thanks, that works for me.',
        'expect_not' => 'Previous reply',
    ],
    [
        'name' => 'outlook_original',
        'input' => "Please change the name to Alex.\n\n-----Original Message-----\nFrom: Support\nSent: Monday\n",
        'expect_contains' => 'Please change the name to Alex.',
        'expect_not' => 'Original Message',
    ],
    [
        'name' => 'our_history_separator',
        'input' => "New question about shipping.\n\n--- Previous chat history (newest first) ---\nSupport: Earlier answer\n",
        'expect_contains' => 'New question about shipping.',
        'expect_not' => 'Earlier answer',
    ],
    [
        'name' => 'quoted_block',
        'input' => "Got it.\n\n> line one\n> line two\n> line three\n> line four\n",
        'expect_contains' => 'Got it.',
        'expect_not' => 'line two',
    ],
];

$failed = 0;
foreach ($cases as $case) {
    $out = EmailReplyExtractor::extractLatest($case['input']);
    $ok = str_contains($out, $case['expect_contains']) && !str_contains($out, $case['expect_not']);
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $case['name'] . ' => ' . json_encode($out) . "\n";
    if (!$ok) {
        $failed++;
    }
}
exit($failed === 0 ? 0 : 1);

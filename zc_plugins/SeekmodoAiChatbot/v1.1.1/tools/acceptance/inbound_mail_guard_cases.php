<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/catalog/includes/library/SeekmodoAiChatbot/InboundMailGuard.php';
require dirname(__DIR__, 2) . '/catalog/includes/library/SeekmodoAiChatbot/PortalToken.php';

use SeekmodoAiChatbot\InboundMailGuard;
use SeekmodoAiChatbot\PortalToken;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    if ($ok) {
        echo "OK  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
};

$mailbox = 'support@example.com';
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'support@example.com'], $mailbox) === 'own_mailbox',
    'own mailbox skipped'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'message_id' => '<chat-12-abc@store.test>'], $mailbox) === 'own_message',
    'minted message-id skipped'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'auto_submitted' => 'auto-replied'], $mailbox) === 'auto_submitted',
    'auto-submitted skipped'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'auto_submitted' => 'no'], $mailbox) === null,
    'auto-submitted no is kept'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'precedence' => 'bulk'], $mailbox) === 'bulk',
    'bulk precedence skipped'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'list_id' => '<news.example.com>'], $mailbox) === 'bulk',
    'list-id skipped'
);
$check(
    InboundMailGuard::ignoreReason(['from_email' => 'shopper@example.com', 'subject' => 'Need help'], $mailbox) === null,
    'plain customer mail is kept'
);

$secret = 'test-secret';
$token = PortalToken::mint('Shopper@Example.com', $secret, 120);
$claims = PortalToken::verify($token, $secret);
$check($claims['email'] === 'shopper@example.com', 'portal token email normalized');
$check($claims['exp'] > time() && $claims['exp'] <= time() + PortalToken::TTL_MAX, 'portal token expiry bounded');
$bad = false;
try {
    PortalToken::verify($token . 'x', $secret);
} catch (\InvalidArgumentException $e) {
    $bad = $e->getMessage() === 'bad_portal_token';
}
$check($bad, 'tampered portal token rejected');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "inbound mail guard cases passed\n";

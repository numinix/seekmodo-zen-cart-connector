<?php
/**
 * Smoke: over_quota PreferLocal recovers on period roll.
 *
 * Run: php zc_plugins/Seekmodo/v1.3.86/catalog/includes/library/Numinix/Seekmodo/OverQuotaRecovery.smoke.php
 */
declare(strict_types=1);

require_once __DIR__ . '/Client.php';

use Numinix\Seekmodo\Client;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$errors = [];

$assert = static function (bool $cond, string $msg) use (&$errors): void {
    if (!$cond) {
        $errors[] = $msg;
    }
};

Client::clearCloudSuggestDenial();

// Past resets_at must not stamp PreferLocal (period already rolled).
$past = gmdate('c', time() - 120);
Client::markCloudSuggestDenied(json_encode([
    'code' => 'over_quota',
    'quota' => 'searches',
    'limit' => 50000,
    'used' => 50000,
    'resets_at' => $past,
], JSON_UNESCAPED_SLASHES));

$assert(
    Client::readOverQuotaEnvelope() === null,
    'mark with past resets_at must not stamp PreferLocal'
);
$assert(
    Client::shouldPreferLocalSuggest() === false,
    'PreferLocal must be false when period has already rolled'
);

// Future resets_at keeps PreferLocal until TTL / soft-probe.
$future = gmdate('c', time() + 3600);
Client::markCloudSuggestDenied(json_encode([
    'code' => 'over_quota',
    'quota' => 'searches',
    'limit' => 50000,
    'used' => 50000,
    'resets_at' => $future,
], JSON_UNESCAPED_SLASHES));

$assert(
    Client::readOverQuotaEnvelope() !== null,
    'mark with future resets_at must stamp PreferLocal'
);
$assert(
    Client::shouldPreferLocalSuggest() === true,
    'PreferLocal must stay true while resets_at is still in the future'
);

// applyBillingSnapshot must soft-probe path for over_quota (no network
// here — without pairing fromConfiguration() is null and sticky stays).
$cleared = Client::applyBillingSnapshot([
    'billing' => ['status' => 'active'],
]);
$assert(
    $cleared === false,
    'applyBillingSnapshot without a live client must not falsely report clear'
);
$assert(
    Client::readOverQuotaEnvelope() !== null,
    'over_quota sticky remains when soft-probe cannot run'
);

Client::clearCloudSuggestDenial();
$assert(
    Client::applyBillingSnapshot(['billing' => ['status' => 'active']]) === true,
    'active billing clears non-over_quota (absent) sticky path'
);

if ($errors !== []) {
    fwrite(STDERR, "OverQuotaRecovery.smoke FAILED:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OverQuotaRecovery.smoke: ok\n";
exit(0);

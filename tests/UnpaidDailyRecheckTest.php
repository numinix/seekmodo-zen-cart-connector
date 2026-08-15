<?php
/**
 * Daily unpaid-recovery plan (2026-08), Zen Cart connector step.
 *
 * shouldPreferLocalSuggest() is the single choke point every suggest /
 * typeahead surface calls BEFORE Client::fromConfiguration() /
 * RemoteConfig::pull() ever run, so once the over-quota / cancelled
 * sticky is stamped, nothing on the suggest path talks to the gateway
 * again to notice a resubscribe or an operator trial extension. This
 * test pins the v1.3.65 fix: a rate-limited daily recheck that force-
 * pulls tenant.snapshot and clears both stickies once
 * `billing.status === 'active'`.
 *
 * Pins:
 *   1. A non-sticky tenant never touches the recheck gate at all.
 *   2. A sticky tenant with no gateway config wired (the common test/
 *      CLI shape) is a safe no-op: sticky remains, gate still stamps
 *      so we don't retry every request.
 *   3. The gate is rate-limited to once per UNPAID_RECHECK_INTERVAL_S —
 *      a second call inside the window does not restamp it.
 *   4. Once the gate has elapsed, a recheck attempt is made again (gate
 *      timestamp refreshes) even though the sticky itself survives a
 *      config-less/unreachable probe.
 *   5. The exact write-through a successful `billing.status === 'active'`
 *      recheck performs — clearing BOTH the over-quota/trial_expired
 *      sticky and the cancelled/tenant-unavailable sticky — is pinned
 *      directly (RemoteConfig::pull() itself talks to a real gateway
 *      over curl, so the network hop is out of scope for this
 *      self-contained suite; AutoPromoter/RemoteConfig already have
 *      their own coverage for the pull/push wire format).
 *
 * Self-contained — no PHPUnit; reaches Client's private static cache
 * helpers via Reflection so it exercises the exact same APCu/$_SESSION
 * dual-cache Client.php itself falls back to when ext-apcu is absent
 * (true in most CI/dev CLIs). Mirrors the pattern of
 * tests/Sprint17TenantUnavailableTest.php.
 *
 *     php tests/UnpaidDailyRecheckTest.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.65/catalog/includes/library/Numinix/Seekmodo/RemoteConfig.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.65/catalog/includes/library/Numinix/Seekmodo/Client.php';

use Numinix\Seekmodo\Client;

// cacheGet()/cacheSet()/cacheDelete() fall back to $_SESSION when
// ext-apcu isn't loaded (the common CLI shape) — start a session so
// that fallback path is actually exercised end-to-end.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$errors = [];
$passed = 0;

function udr_assert(bool $cond, string $label, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $label;
}

$ref = new ReflectionClass(Client::class);

function udr_cache_set(ReflectionClass $ref, string $key, $value, int $ttl): void
{
    $m = $ref->getMethod('cacheSet');
    $m->setAccessible(true);
    $m->invoke(null, $key, $value, $ttl);
}

/** @return mixed */
function udr_cache_get(ReflectionClass $ref, string $key)
{
    $m = $ref->getMethod('cacheGet');
    $m->setAccessible(true);
    return $m->invoke(null, $key);
}

function udr_cache_delete(ReflectionClass $ref, string $key): void
{
    $m = $ref->getMethod('cacheDelete');
    $m->setAccessible(true);
    $m->invoke(null, $key);
}

$overQuotaKey = $ref->getConstant('OVER_QUOTA_KEY');
$subscriptionKey = $ref->getConstant('SUBSCRIPTION_KEY');
$recheckKey = $ref->getConstant('UNPAID_RECHECK_KEY');
$intervalS = $ref->getConstant('UNPAID_RECHECK_INTERVAL_S');
$subActive = $ref->getConstant('SUB_STATE_ACTIVE');
$subCancelled = $ref->getConstant('SUB_STATE_CANCELLED');

udr_assert(is_string($overQuotaKey) && $overQuotaKey !== '', 'OVER_QUOTA_KEY constant resolves', $errors, $passed);
udr_assert(is_string($recheckKey) && $recheckKey !== '', 'UNPAID_RECHECK_KEY constant resolves', $errors, $passed);
udr_assert($intervalS === 86400, 'UNPAID_RECHECK_INTERVAL_S is once-per-day', $errors, $passed);

$reset = static function () use ($ref, $overQuotaKey, $subscriptionKey, $recheckKey): void {
    udr_cache_delete($ref, $overQuotaKey);
    udr_cache_delete($ref, $subscriptionKey);
    udr_cache_delete($ref, $recheckKey);
};

// -----------------------------------------------------------------
// 1. Not sticky — shouldPreferLocalSuggest() is false and the daily
//    recheck gate is never touched (nothing to recover from).
// -----------------------------------------------------------------
$reset();
udr_assert(Client::shouldPreferLocalSuggest() === false, 'no sticky -> prefers cloud', $errors, $passed);
udr_assert(udr_cache_get($ref, $recheckKey) === null, 'no sticky -> recheck gate left unset', $errors, $passed);

// -----------------------------------------------------------------
// 2. Sticky set, no gateway config wired (RemoteConfig::
//    fromConfiguration() returns null in this CLI/test shape) — the
//    recheck must be a safe no-op: sticky remains, no exception, and
//    the gate is still stamped so we don't hammer the check on every
//    single storefront request until the interval elapses.
// -----------------------------------------------------------------
$reset();
udr_cache_set($ref, $overQuotaKey, '{"code":"trial_expired"}', 3600);
udr_assert(Client::shouldPreferLocalSuggest() === true, 'sticky + no gateway config -> still prefers local', $errors, $passed);
$firstStamp = udr_cache_get($ref, $recheckKey);
udr_assert(is_int($firstStamp), 'recheck gate stamped even when config is missing', $errors, $passed);

// -----------------------------------------------------------------
// 3. Rate limit — a fresh gate timestamp is NOT re-stamped by a
//    second call inside UNPAID_RECHECK_INTERVAL_S.
// -----------------------------------------------------------------
sleep(1);
Client::shouldPreferLocalSuggest();
$secondStamp = udr_cache_get($ref, $recheckKey);
udr_assert($firstStamp === $secondStamp, 'recheck gate not restamped inside the daily interval', $errors, $passed);

// -----------------------------------------------------------------
// 4. Once the gate has elapsed (simulated by backdating it past the
//    interval), the next call attempts a recheck again — sticky
//    survives the config-less probe, but the gate timestamp refreshes
//    to "now" so the next attempt waits another full day.
// -----------------------------------------------------------------
$reset();
udr_cache_set($ref, $overQuotaKey, '{"code":"over_quota"}', 3600);
udr_cache_set($ref, $recheckKey, time() - $intervalS - 5, $intervalS);
udr_assert(Client::shouldPreferLocalSuggest() === true, 'elapsed gate + no config -> sticky survives', $errors, $passed);
$refreshed = udr_cache_get($ref, $recheckKey);
udr_assert(is_int($refreshed) && $refreshed > (time() - 5), 'elapsed gate refreshes to "now" after the attempt', $errors, $passed);

// -----------------------------------------------------------------
// 5. Write-through shape a successful billing.status==='active'
//    recheck performs: BOTH the over-quota/trial_expired sticky
//    (clearCloudSuggestDenial) and the cancelled/tenant-unavailable
//    sticky (SUBSCRIPTION_KEY -> active) are cleared, matching the
//    2xx-success path in Client::call() so a background recheck and
//    a live metered success converge on the same state.
// -----------------------------------------------------------------
$reset();
udr_cache_set($ref, $overQuotaKey, '{"code":"trial_expired"}', 3600);
udr_cache_set($ref, $subscriptionKey, $subCancelled, 3600);
udr_assert(Client::shouldPreferLocalSuggest() === true, 'over-quota + cancelled stickies -> prefers local', $errors, $passed);
udr_assert(Client::readSubscriptionState() === $subCancelled, 'cancelled sticky wins subscription-state read', $errors, $passed);

// Simulate the write-through maybeRecheckUnpaidState() performs once
// RemoteConfig::pull() reports billing.status === 'active'.
udr_cache_set($ref, $subscriptionKey, $subActive, 3600);
Client::clearCloudSuggestDenial();
udr_assert(Client::readSubscriptionState() === $subActive, 'subscription state reads active after clear', $errors, $passed);
udr_assert(Client::shouldPreferLocalSuggest() === false, 'clearing both stickies restores cloud suggest', $errors, $passed);

// -----------------------------------------------------------------
// 6. Client::applyBillingSnapshot() — the shared helper both the
//    background daily recheck AND the admin "Refresh snapshot"
//    button (numinix_seekmodo_connect.php) now call. Pins the exact
//    bug Bugbot flagged on the first cut of this feature: refresh
//    used to only mirror mode/FSM fields and never actually cleared
//    the sticky, so "click Refresh snapshot for immediate restore"
//    silently did nothing for a resubscribed/extended tenant.
// -----------------------------------------------------------------
$reset();
udr_cache_set($ref, $overQuotaKey, '{"code":"trial_expired"}', 3600);
udr_assert(
    Client::applyBillingSnapshot(['billing' => ['status' => 'active']]) === true,
    'applyBillingSnapshot returns true and clears on active',
    $errors,
    $passed
);
udr_assert(Client::shouldPreferLocalSuggest() === false, 'applyBillingSnapshot cleared the sticky', $errors, $passed);

$reset();
udr_cache_set($ref, $overQuotaKey, '{"code":"trial_expired"}', 3600);
udr_assert(
    Client::applyBillingSnapshot(['billing' => ['status' => 'trial_expired']]) === false,
    'applyBillingSnapshot returns false and leaves sticky on non-active status',
    $errors,
    $passed
);
udr_assert(Client::shouldPreferLocalSuggest() === true, 'sticky still set after non-active applyBillingSnapshot', $errors, $passed);

udr_assert(Client::applyBillingSnapshot(null) === false, 'applyBillingSnapshot(null) is a safe no-op', $errors, $passed);
udr_assert(Client::applyBillingSnapshot(['mode' => 'enforce']) === false, 'applyBillingSnapshot ignores rows with no billing key', $errors, $passed);

$reset();

echo "\nUnpaidDailyRecheckTest: {$passed} passed, " . count($errors) . " failed\n";
foreach ($errors as $err) {
    echo "  FAIL {$err}\n";
}
exit(empty($errors) ? 0 : 1);

<?php

declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Soft / sitewide admin billing notices for unpaid, over-quota, and
 * paused Seekmodo tenants.
 *
 * Soft banner (Connect page): always while paired + sticky denial.
 * Sitewide (admin homepage): once per admin session episode until
 * dismissed; dismissals wipe when {@see Client::clearCloudSuggestDenial()}
 * runs so a future lapse can notify again.
 */
final class BillingAdminNotice
{
    public const BILLING_URL = 'https://seekmodo.com/billing';

    /**
     * Resolve the merchant-facing reason code from subscription state
     * + optional 402 envelope. Returns null when no notice should show.
     */
    public static function resolveReasonCode(?string $subState = null, ?array $envelope = null): ?string
    {
        $state = $subState;
        if ($state === null && class_exists(Client::class)) {
            $state = Client::readSubscriptionState();
        }
        if ($state === 'cancelled') {
            return 'cancelled';
        }
        if ($state !== 'over_quota') {
            return null;
        }
        if ($envelope === null && class_exists(Client::class)) {
            $envelope = Client::readOverQuotaEnvelope();
        }
        $code = '';
        if (is_array($envelope)) {
            $raw = $envelope['code'] ?? '';
            $code = is_string($raw) ? trim($raw) : '';
        }
        if ($code === 'trial_expired') {
            return 'trial_expired';
        }
        if ($code === 'cancelled' || $code === 'tenant_paused') {
            return 'cancelled';
        }

        return 'over_quota';
    }

    public static function softCopy(string $reason): string
    {
        switch ($reason) {
            case 'trial_expired':
                return 'Seekmodo trial ended — storefront search is Enhanced Native (local). '
                    . 'Subscribe at seekmodo.com/billing to restore cloud Seekmodo. '
                    . 'Staying on Enhanced Native is free.';
            case 'cancelled':
                return 'Seekmodo account paused — storefront uses Enhanced Native. '
                    . 'Reactivate at seekmodo.com/billing.';
            case 'over_quota':
            default:
                return 'Seekmodo cloud search quota reached — Enhanced Native until the period resets. '
                    . 'Upgrade or wait — staying on Enhanced Native is fine.';
        }
    }

    public static function isSoftStyle(string $reason): bool
    {
        return $reason === 'trial_expired' || $reason === 'over_quota';
    }

    public static function adminId(): string
    {
        if (!empty($_SESSION['admin_id'])) {
            return (string) $_SESSION['admin_id'];
        }
        if (!empty($_SESSION['admin']['id'])) {
            return (string) $_SESSION['admin']['id'];
        }

        return '0';
    }

    public static function isDismissed(string $reason): bool
    {
        if ($reason === '') {
            return false;
        }
        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            $_SESSION['admin'] = [];
        }
        $map = $_SESSION['admin']['seekmodo_billing_dismissed'] ?? null;
        if (!is_array($map)) {
            return false;
        }

        return !empty($map[$reason]);
    }

    public static function markDismissed(string $reason): void
    {
        if ($reason === '') {
            return;
        }
        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            $_SESSION['admin'] = [];
        }
        if (!isset($_SESSION['admin']['seekmodo_billing_dismissed'])
            || !is_array($_SESSION['admin']['seekmodo_billing_dismissed'])
        ) {
            $_SESSION['admin']['seekmodo_billing_dismissed'] = [];
        }
        $_SESSION['admin']['seekmodo_billing_dismissed'][$reason] = 1;
    }

    /**
     * Wipe all per-admin dismiss flags for this session (episode end).
     * Called from {@see Client::clearCloudSuggestDenial()}.
     */
    public static function clearAllDismissals(): void
    {
        if (isset($_SESSION['admin']) && is_array($_SESSION['admin'])) {
            unset($_SESSION['admin']['seekmodo_billing_dismissed']);
        }
    }

    /**
     * Whether the sitewide once-per-admin notice should appear.
     * Requires pairing + sticky denial + not yet dismissed this episode.
     */
    public static function shouldShowSitewide(bool $isPaired): bool
    {
        if (!$isPaired) {
            return false;
        }
        $reason = self::resolveReasonCode();
        if ($reason === null) {
            return false;
        }

        return !self::isDismissed($reason);
    }

    public static function connectPagePath(): string
    {
        if (defined('FILENAME_NUMINIX_SEEKMODO_CONNECT')) {
            return (string) constant('FILENAME_NUMINIX_SEEKMODO_CONNECT');
        }

        return 'numinix_seekmodo_connect';
    }
}

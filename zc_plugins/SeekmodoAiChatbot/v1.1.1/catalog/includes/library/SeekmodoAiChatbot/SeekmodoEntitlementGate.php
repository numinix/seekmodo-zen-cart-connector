<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Requires a paired Seekmodo install on an active subscription before the chatbot may run.
 */
final class SeekmodoEntitlementGate
{
    public const REASON_CONNECTOR_MISSING = 'connector_missing';
    public const REASON_UNPAIRED = 'unpaired';
    public const REASON_UNPAID = 'unpaid';
    public const REASON_OK = 'ok';

    /**
     * @return array{ok: bool, reason: string, paired: bool, subscription: string, tenant_id: string, message: string}
     */
    public static function evaluate(): array
    {
        $tenantId = '';
        if (defined('NUMINIX_SEEKMODO_TENANT_ID')) {
            $tenantId = trim((string) NUMINIX_SEEKMODO_TENANT_ID);
        }

        $connectorPresent = self::connectorPresent();
        if (!$connectorPresent) {
            return [
                'ok' => false,
                'reason' => self::REASON_CONNECTOR_MISSING,
                'paired' => false,
                'subscription' => 'unknown',
                'tenant_id' => $tenantId,
                'message' => 'Install and pair Seekmodo (included in this download). Chat requires an active Seekmodo plan.',
            ];
        }

        $paired = (bool) preg_match('~^[a-z0-9][a-z0-9_\-]{1,63}$~', $tenantId);
        $secret = defined('NUMINIX_SEEKMODO_SHARED_SECRET')
            ? trim((string) NUMINIX_SEEKMODO_SHARED_SECRET)
            : '';
        if ($paired && $secret === '') {
            $paired = false;
        }

        if (!$paired) {
            return [
                'ok' => false,
                'reason' => self::REASON_UNPAIRED,
                'paired' => false,
                'subscription' => 'unknown',
                'tenant_id' => $tenantId,
                'message' => 'Pair this store with Seekmodo (Tools → Seekmodo Connect) before enabling the chatbot.',
            ];
        }

        $subscription = 'unknown';
        if (function_exists('numinix_seekmodo_subscription_state')) {
            $subscription = (string) numinix_seekmodo_subscription_state();
        } elseif (class_exists('Numinix\\Seekmodo\\Client')) {
            $subscription = (string) \Numinix\Seekmodo\Client::readSubscriptionState();
        }

        $subscription = strtolower(trim($subscription));
        if (in_array($subscription, ['cancelled', 'paused', 'unpaid'], true)) {
            return [
                'ok' => false,
                'reason' => self::REASON_UNPAID,
                'paired' => true,
                'subscription' => $subscription,
                'tenant_id' => $tenantId,
                'message' => 'Seekmodo subscription is not active (' . $subscription . '). Reactivate the plan to use Seekmodo AI Chatbot.',
            ];
        }

        return [
            'ok' => true,
            'reason' => self::REASON_OK,
            'paired' => true,
            'subscription' => $subscription !== '' ? $subscription : 'active',
            'tenant_id' => $tenantId,
            'message' => 'Seekmodo is paired and ready. Chatbot Seekmodo searches meter against your plan.',
        ];
    }

    public static function allowsStorefront(): bool
    {
        return self::evaluate()['ok'] === true;
    }

    private static function connectorPresent(): bool
    {
        if (function_exists('numinix_seekmodo_subscription_state')) {
            return true;
        }
        if (class_exists('Numinix\\Seekmodo\\Client')) {
            return true;
        }
        if (defined('NUMINIX_SEEKMODO_TENANT_ID') || defined('NUMINIX_SEEKMODO_GATEWAY_URL')) {
            return true;
        }
        $catalog = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '';
        if ($catalog !== '' && is_dir(rtrim($catalog, '/\\') . '/zc_plugins/Seekmodo')) {
            return true;
        }
        return false;
    }
}

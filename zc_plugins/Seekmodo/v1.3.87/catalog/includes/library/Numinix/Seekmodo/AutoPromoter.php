<?php

namespace Numinix\Seekmodo;

/**
 * Auto-promotion state machine for the Seekmodo connector.
 *
 * When `NUMINIX_SEEKMODO_MODE=active` (the new default), this class
 * decides on every search request whether to behave as `shadow` or
 * `enforce` and self-promotes between phases based on observed
 * gateway health.
 *
 * Phases:
 *
 *   bootstrap            Just installed. Behave as shadow until we have
 *                        > MIN_BOOTSTRAP_AGE_S of wall time AND at
 *                        least MIN_BOOTSTRAP_OBSERVED gateway calls
 *                        with ≤ MAX_BOOTSTRAP_ERROR_RATE error rate.
 *
 *   shadow_observing     Active observation. Promote to `enforced`
 *                        once we have ≥ PROMOTE_MIN_OBSERVED requests
 *                        with ≤ PROMOTE_MAX_ERROR_RATE error rate
 *                        AND wall-time on shadow ≥ PROMOTE_MIN_AGE_S.
 *
 *   enforced             Serving gateway results. Demote to
 *                        `shadow_recovering` if we record
 *                        ≥ DEMOTE_WINDOW_ERRORS errors inside a 1h
 *                        rolling window. The circuit breaker still
 *                        catches per-request failures inside this
 *                        phase — demotion is the slower trigger that
 *                        backs off when the gateway is unhealthy for
 *                        an extended time.
 *
 *   shadow_recovering    Healing window. Behave as shadow for
 *                        RECOVERY_DURATION_S, then re-enter
 *                        `shadow_observing` and let the normal
 *                        promotion gate decide whether to re-enforce.
 *
 * The state machine is deliberately conservative: it errs on the side
 * of "stay in shadow" (which means shoppers see native results, no
 * regressions) until it has clear evidence that the gateway is
 * healthy.
 *
 * Operator overrides:
 *   - Setting MODE=off, shadow, or enforce explicitly bypasses this
 *     state machine. `active` is the only value that engages it.
 *   - Setting NUMINIX_SEEKMODO_AUTO_PROMOTE=false stops the state
 *     machine from advancing — useful while debugging a flaky gateway.
 */
final class AutoPromoter
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_ENFORCE = 'enforce';
    public const MODE_ACTIVE = 'active';

    // Bootstrap thresholds — first phase, kept low so a freshly
    // installed plugin doesn't sit in shadow forever on a low-traffic
    // store.
    private const MIN_BOOTSTRAP_AGE_S = 3600;        // 1h on the wall clock
    private const MIN_BOOTSTRAP_OBSERVED = 50;       // at least 50 gateway calls
    private const MAX_BOOTSTRAP_ERROR_RATE = 0.20;   // ≤ 20% errors

    // Promotion thresholds — second phase.
    private const PROMOTE_MIN_OBSERVED = 200;        // larger sample
    private const PROMOTE_MAX_ERROR_RATE = 0.05;     // ≤ 5% errors
    private const PROMOTE_MIN_AGE_S = 86400;         // ≥ 24h on shadow

    // Demotion threshold — when enforced and the gateway is hurting.
    private const DEMOTE_WINDOW_ERRORS = 25;         // 25 errors in 1h

    // Recovery duration — how long to stay in shadow_recovering before
    // re-entering observing.
    private const RECOVERY_DURATION_S = 3600;        // 1h cooldown

    private PromotionStore $store;

    public function __construct(?PromotionStore $store = null)
    {
        $this->store = $store ?? new PromotionStore();
    }

    /**
     * Resolve the runtime mode for the current request.
     *
     * @param string $configuredMode Raw NUMINIX_SEEKMODO_MODE value
     * @return 'off'|'shadow'|'enforce'
     */
    public function resolveMode(string $configuredMode): string
    {
        $configured = strtolower(trim($configuredMode));
        if ($configured === self::MODE_OFF) {
            return self::MODE_OFF;
        }
        if ($configured === self::MODE_SHADOW || $configured === self::MODE_ENFORCE) {
            return $configured;
        }
        if ($configured !== self::MODE_ACTIVE) {
            // Unknown value — fail safe.
            return self::MODE_OFF;
        }
        // active: consult the state machine.
        $state = $this->store->currentState();
        switch ($state) {
            case PromotionStore::STATE_ENFORCED:
                return self::MODE_ENFORCE;
            case PromotionStore::STATE_BOOTSTRAP:
            case PromotionStore::STATE_SHADOW_OBSERVING:
            case PromotionStore::STATE_SHADOW_RECOVERING:
            default:
                return self::MODE_SHADOW;
        }
    }

    /**
     * Record a single gateway outcome and let the state machine react.
     * Should be called after every gateway call regardless of mode —
     * shadow observations feed promotion, enforced observations feed
     * demotion.
     */
    public function observe(bool $ok): void
    {
        $this->store->recordObservation($ok);
        // Auto-promote is opt-out — falsy values keep the FSM frozen.
        if (!$this->isAutoPromoteEnabled()) {
            return;
        }
        $this->advance();
    }

    /**
     * Force the state machine to evaluate now. Called from `observe()`
     * but also exposed so an admin "Re-evaluate" button (or a tool
     * like tools/verify_redline_seekmodo.py) can poke the FSM
     * out-of-band.
     */
    public function advance(): array
    {
        $state = $this->store->currentState();
        $counters = $this->store->counters();
        $ageOnState = $this->ageOnCurrentState();
        $errorRate = $counters['observed'] > 0
            ? ($counters['errors'] / $counters['observed'])
            : 0.0;

        $decision = [
            'state' => $state,
            'changed' => false,
            'reason' => 'no_change',
            'counters' => $counters,
            'age_on_state_s' => $ageOnState,
            'error_rate' => $errorRate,
        ];

        switch ($state) {
            case PromotionStore::STATE_BOOTSTRAP:
                if (
                    $counters['first_seen_age_s'] >= self::MIN_BOOTSTRAP_AGE_S
                    && $counters['observed'] >= self::MIN_BOOTSTRAP_OBSERVED
                    && $errorRate <= self::MAX_BOOTSTRAP_ERROR_RATE
                ) {
                    $this->store->transitionTo(
                        PromotionStore::STATE_SHADOW_OBSERVING,
                        sprintf(
                            'bootstrap complete: observed=%d, errors=%d, rate=%.3f',
                            $counters['observed'],
                            $counters['errors'],
                            $errorRate
                        )
                    );
                    $decision['changed'] = true;
                    $decision['reason'] = 'bootstrap_complete';
                }
                break;

            case PromotionStore::STATE_SHADOW_OBSERVING:
                if (
                    $ageOnState >= self::PROMOTE_MIN_AGE_S
                    && $counters['observed'] >= self::PROMOTE_MIN_OBSERVED
                    && $errorRate <= self::PROMOTE_MAX_ERROR_RATE
                ) {
                    $this->store->transitionTo(
                        PromotionStore::STATE_ENFORCED,
                        sprintf(
                            'auto-promote: observed=%d, errors=%d, rate=%.3f, age=%ds',
                            $counters['observed'],
                            $counters['errors'],
                            $errorRate,
                            $ageOnState
                        )
                    );
                    $decision['changed'] = true;
                    $decision['reason'] = 'promoted_to_enforce';
                }
                break;

            case PromotionStore::STATE_ENFORCED:
                if ($counters['window_errors'] >= self::DEMOTE_WINDOW_ERRORS) {
                    $this->store->transitionTo(
                        PromotionStore::STATE_SHADOW_RECOVERING,
                        sprintf(
                            'auto-demote: %d errors inside 1h window',
                            $counters['window_errors']
                        )
                    );
                    $decision['changed'] = true;
                    $decision['reason'] = 'demoted_to_recovering';
                }
                break;

            case PromotionStore::STATE_SHADOW_RECOVERING:
                if ($ageOnState >= self::RECOVERY_DURATION_S) {
                    $this->store->transitionTo(
                        PromotionStore::STATE_SHADOW_OBSERVING,
                        sprintf(
                            'recovery complete after %ds; re-observing',
                            $ageOnState
                        )
                    );
                    $decision['changed'] = true;
                    $decision['reason'] = 'recovery_complete';
                }
                break;
        }

        if ($decision['changed']) {
            $decision['state'] = $this->store->currentState();
            $this->pushSnapshotToGateway('fsm_transition');
        }
        return $decision;
    }

    /**
     * Best-effort push of the current FSM snapshot up to the gateway
     * via tenant.snapshot. Called on every state change; the indexer
     * cron also calls this once per run via pushSnapshot() so the
     * admin UI's ConnectorStatusCard stays fresh between transitions.
     *
     * Failures are swallowed — gateway-down should never break the
     * storefront's hot path.
     */
    public function pushSnapshot(string $reason = 'periodic', array $extra = []): bool
    {
        return $this->pushSnapshotToGateway($reason, $extra);
    }

    private function pushSnapshotToGateway(string $reason, array $extra = []): bool
    {
        if (!class_exists(RemoteConfig::class)) {
            return false;
        }
        $rc = RemoteConfig::fromConfiguration();
        if ($rc === null) {
            return false;
        }
        $snap = $this->snapshot();
        $stateSinceIso = $snap['state_since_epoch'] > 0
            ? gmdate('c', (int) $snap['state_since_epoch'])
            : null;
        try {
            $payload = [
                'auto_state'       => (string) $snap['state'],
                'auto_state_since' => $stateSinceIso,
                'auto_history'     => $snap['history'],
                'observed_count'   => (int) ($snap['counters']['observed'] ?? 0),
                'errors_count'     => (int) ($snap['counters']['errors'] ?? 0),
            ];
            if (defined('NUMINIX_SEEKMODO_LAST_FULL_PUSH_AT')) {
                $payload['last_full_push_at'] = (int) NUMINIX_SEEKMODO_LAST_FULL_PUSH_AT;
            }
            if (defined('NUMINIX_SEEKMODO_LAST_FULL_PUSH_DURATION_S')) {
                $payload['last_full_push_duration_s'] = (int) NUMINIX_SEEKMODO_LAST_FULL_PUSH_DURATION_S;
            }
            if (defined('NUMINIX_SEEKMODO_LAST_FULL_PUSH_DOC_COUNT')) {
                $payload['last_full_push_doc_count'] = (int) NUMINIX_SEEKMODO_LAST_FULL_PUSH_DOC_COUNT;
            }
            if ($extra !== []) {
                foreach ($extra as $key => $value) {
                    $payload[$key] = $value;
                }
            }
            return $rc->push($payload);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function snapshot(): array
    {
        return [
            'state' => $this->store->currentState(),
            'state_since_epoch' => $this->store->stateSince(),
            'age_on_state_s' => $this->ageOnCurrentState(),
            'counters' => $this->store->counters(),
            'history' => $this->store->history(),
            'thresholds' => [
                'bootstrap' => [
                    'min_age_s' => self::MIN_BOOTSTRAP_AGE_S,
                    'min_observed' => self::MIN_BOOTSTRAP_OBSERVED,
                    'max_error_rate' => self::MAX_BOOTSTRAP_ERROR_RATE,
                ],
                'promote' => [
                    'min_age_s' => self::PROMOTE_MIN_AGE_S,
                    'min_observed' => self::PROMOTE_MIN_OBSERVED,
                    'max_error_rate' => self::PROMOTE_MAX_ERROR_RATE,
                ],
                'demote_window_errors' => self::DEMOTE_WINDOW_ERRORS,
                'recovery_duration_s' => self::RECOVERY_DURATION_S,
            ],
        ];
    }

    private function ageOnCurrentState(): int
    {
        $since = $this->store->stateSince();
        if ($since === 0) {
            return 0;
        }
        return max(0, time() - $since);
    }

    private function isAutoPromoteEnabled(): bool
    {
        if (!defined('NUMINIX_SEEKMODO_AUTO_PROMOTE')) {
            return true;
        }
        $v = strtolower(trim((string)constant('NUMINIX_SEEKMODO_AUTO_PROMOTE')));
        return !in_array($v, ['false', '0', 'no', 'off'], true);
    }
}

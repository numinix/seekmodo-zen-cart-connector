<?php

declare(strict_types=1);

/**
 * Lightweight PHPUnit-free smoke for BillingAdminNotice pure helpers.
 * Run: php zc_plugins/Seekmodo/v1.3.52/catalog/includes/library/Numinix/Seekmodo/BillingAdminNotice.smoke.php
 */

require_once __DIR__ . '/BillingAdminNotice.php';

use Numinix\Seekmodo\BillingAdminNotice;

$failures = 0;
function expect_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $failures++;
    }
}

expect_true(
    strpos(BillingAdminNotice::softCopy('trial_expired'), 'Enhanced Native') !== false,
    'trial copy mentions EN'
);
expect_true(
    BillingAdminNotice::resolveReasonCode('cancelled', null) === 'cancelled',
    'cancelled reason'
);
expect_true(
    BillingAdminNotice::resolveReasonCode('over_quota', ['code' => 'trial_expired']) === 'trial_expired',
    'envelope trial_expired'
);
expect_true(
    BillingAdminNotice::isSoftStyle('over_quota') === true,
    'over_quota soft'
);
expect_true(
    BillingAdminNotice::isSoftStyle('cancelled') === false,
    'cancelled not soft'
);

if ($failures > 0) {
    fwrite(STDERR, "$failures failure(s)\n");
    exit(1);
}
echo "BillingAdminNotice smoke OK\n";

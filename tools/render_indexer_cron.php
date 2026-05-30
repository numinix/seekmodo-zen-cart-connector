<?php
/**
 * Translate the W6b `indexer_schedule` enum into a cron line.
 *
 * Used by the operator-side ``tools/install_redline_connector.py`` in
 * the seekmodo monorepo to populate ``/etc/cron.d/numinix-seekmodo-
 * <tenant>`` after install / update. Standalone PHP (no Zen Cart
 * bootstrap) so the install script can ``shell_exec`` it across
 * SFTP / paramiko without a real database connection.
 *
 * Inputs (all positional CLI args, all required):
 *   1. schedule   one of: hourly, every_4h, every_12h, daily, manual
 *   2. command    the full shell command line to run, e.g.
 *                 ``cd /home/redlines/public_html/catalog && /usr/bin/php transfer_products.php >>/var/log/numinix_seekmodo_indexer.log 2>&1``
 *   3. user       Unix user the cron line runs as, e.g. ``redlines``
 *
 * Output: a single cron line on stdout, terminated with a newline.
 * For ``schedule=manual`` the script prints a comment marker so the
 * caller can write the file unconditionally without conditional logic
 * — the file shape stays predictable across schedules.
 *
 * Exit codes:
 *   0  success — line emitted
 *   2  invalid schedule
 *   3  missing args
 */

declare(strict_types=1);

const SCHEDULES = [
    // Top of every hour — busy stores / large catalogs.
    'hourly'    => '0 * * * *',
    // Every 4 hours starting at minute 17 (avoid the busy top-of-hour).
    'every_4h'  => '17 */4 * * *',
    // Every 12 hours at 03:23 and 15:23.
    'every_12h' => '23 3,15 * * *',
    // Once a day at 03:11 — quiet local night for most US tenants.
    'daily'     => '11 3 * * *',
    // No automatic schedule.
    'manual'    => null,
];

function fail(string $msg, int $code): never
{
    fwrite(STDERR, "render_indexer_cron: {$msg}\n");
    exit($code);
}

if ($argc < 4) {
    fail("usage: render_indexer_cron.php <schedule> <command> <user>", 3);
}

$schedule = strtolower(trim((string) $argv[1]));
$command  = trim((string) $argv[2]);
$user     = trim((string) $argv[3]);

if ($command === '' || $user === '') {
    fail("command and user must be non-empty", 3);
}
if (!array_key_exists($schedule, SCHEDULES)) {
    fail(
        "unknown schedule '{$schedule}'; expected one of: "
        . implode(', ', array_keys(SCHEDULES)),
        2
    );
}

if ($schedule === 'manual') {
    fwrite(STDOUT, "# numinix-seekmodo: indexer_schedule=manual — operator owns the cron\n");
    exit(0);
}

$expr = SCHEDULES[$schedule];
fwrite(STDOUT, "# numinix-seekmodo: indexer_schedule={$schedule}\n");
fwrite(STDOUT, "{$expr} {$user} {$command}\n");
exit(0);

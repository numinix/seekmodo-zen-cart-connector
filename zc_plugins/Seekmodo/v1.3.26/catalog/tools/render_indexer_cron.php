<?php
/**
 * Translate indexer_schedule + offset into managed cron blocks.
 *
 * Usage:
 *   render_indexer_cron.php <schedule> <full_command> <user> [offset_min] [delta_command]
 *
 * When offset_min and delta_command are provided, emits two blocks:
 *   # numinix-seekmodo-managed: full_push
 *   # numinix-seekmodo-managed: delta_tick
 */
declare(strict_types=1);

const SCHEDULES = [
    'hourly'    => ['minute' => 0, 'hour' => '*', 'dom' => '*', 'month' => '*', 'dow' => '*'],
    'every_4h'  => ['minute' => 17, 'hour' => '*/4', 'dom' => '*', 'month' => '*', 'dow' => '*'],
    'every_12h' => ['minute' => 23, 'hour' => '3,15', 'dom' => '*', 'month' => '*', 'dow' => '*'],
    'daily'     => ['minute' => 11, 'hour' => '3', 'dom' => '*', 'month' => '*', 'dow' => '*'],
    'manual'    => null,
];

function fail(string $msg, int $code): never
{
    fwrite(STDERR, "render_indexer_cron: {$msg}\n");
    exit($code);
}

function shift_minute(int $baseMinute, int $offset): int
{
    return ($baseMinute + $offset) % 60;
}

function build_expr(array $parts): string
{
    return sprintf(
        '%d %s %s %s %s',
        $parts['minute'],
        $parts['hour'],
        $parts['dom'],
        $parts['month'],
        $parts['dow']
    );
}

function delta_minutes(int $offset): string
{
    $base = $offset % 15;
    return implode(',', [$base, $base + 15, $base + 30, $base + 45]);
}

if ($argc < 4) {
    fail('usage: render_indexer_cron.php <schedule> <full_command> <user> [offset_min] [delta_command]', 3);
}

$schedule = strtolower(trim((string) $argv[1]));
$command  = trim((string) $argv[2]);
$user     = trim((string) $argv[3]);
$offset   = isset($argv[4]) ? max(0, min(59, (int) $argv[4])) : 0;
$deltaCmd = isset($argv[5]) ? trim((string) $argv[5]) : '';

if ($command === '' || $user === '') {
    fail('command and user must be non-empty', 3);
}
if (!array_key_exists($schedule, SCHEDULES)) {
    fail(
        'unknown schedule \'' . $schedule . '\'; expected one of: '
        . implode(', ', array_keys(SCHEDULES)),
        2
    );
}

if ($schedule === 'manual') {
    fwrite(STDOUT, "# numinix-seekmodo-managed: full_push indexer_schedule=manual — operator owns full push cron\n");
    if ($deltaCmd !== '') {
        $deltaExpr = delta_minutes($offset) . ' * * * *';
        fwrite(STDOUT, "# numinix-seekmodo-managed: delta_tick\n");
        fwrite(STDOUT, "{$deltaExpr} {$user} {$deltaCmd}\n");
    }
    exit(0);
}

$template = SCHEDULES[$schedule];
if ($template === null) {
    exit(0);
}
$parts = $template;
$parts['minute'] = shift_minute((int) $parts['minute'], $offset);
$expr = build_expr($parts);

fwrite(STDOUT, "# numinix-seekmodo-managed: full_push indexer_schedule={$schedule} offset={$offset}\n");
fwrite(STDOUT, "{$expr} {$user} {$command}\n");

if ($deltaCmd !== '') {
    $deltaExpr = delta_minutes($offset) . ' * * * *';
    fwrite(STDOUT, "# numinix-seekmodo-managed: delta_tick\n");
    fwrite(STDOUT, "{$deltaExpr} {$user} {$deltaCmd}\n");
}

exit(0);

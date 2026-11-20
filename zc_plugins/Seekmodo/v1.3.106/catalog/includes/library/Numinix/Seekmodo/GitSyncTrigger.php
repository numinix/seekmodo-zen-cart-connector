<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Best-effort git auto-sync after an in-plugin connector upgrade.
 *
 * Tenants with the Numinix zencart_git tooling installed have
 * `cron/sync-to-git.sh` + `deploy/config.php` beside the storefront
 * docroot.  When `enable_git_push` is true, we invoke that script
 * immediately after UpdateApplier succeeds so the upgraded plugin
 * tree is committed without waiting for the next 5-minute cron tick.
 *
 * The script itself handles branch propagation (cherry-picks to
 * master/main + staging when configured via `propagate_branches`).
 *
 * Silent no-op when:
 *   - DIR_FS_CATALOG is undefined (CLI contexts we don't recognise)
 *   - deploy/config.php or cron/sync-to-git.sh is absent
 *   - `enable_git_push` is false in config
 *   - proc_open / exec is disabled on the host
 */
final class GitSyncTrigger
{
    /**
     * @return array{ok: bool, skipped?: bool, error?: string, detail?: string}
     */
    public static function maybeRunAfterConnectorUpdate(string $oldVersion, string $newVersion): array
    {
        if (!defined('DIR_FS_CATALOG') || DIR_FS_CATALOG === '') {
            return ['ok' => true, 'skipped' => true, 'detail' => 'no catalog root'];
        }

        $catalogRoot = rtrim(str_replace('\\', '/', (string) DIR_FS_CATALOG), '/');
        $configPath = self::resolveConfigPath($catalogRoot);
        if ($configPath === null) {
            return ['ok' => true, 'skipped' => true, 'detail' => 'no deploy/config.php'];
        }

        if (!self::gitPushEnabled($configPath)) {
            return ['ok' => true, 'skipped' => true, 'detail' => 'enable_git_push=false'];
        }

        $scriptPath = $catalogRoot . '/cron/sync-to-git.sh';
        if (!is_file($scriptPath)) {
            return ['ok' => true, 'skipped' => true, 'detail' => 'sync-to-git.sh missing'];
        }

        $commitMsg = sprintf(
            '[seekmodo-connector] Upgrade %s -> %s via admin',
            $oldVersion,
            $newVersion
        );
        $env = self::buildEnv($configPath);

        $cmd = sprintf(
            'bash %s %s --commit-message %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($configPath),
            escapeshellarg($commitMsg)
        );

        $output = self::runCommand($cmd, $env);
        if ($output === null) {
            return ['ok' => false, 'error' => 'unable to spawn sync-to-git.sh (proc_open/exec disabled?)'];
        }

        if (strpos($output, 'ERROR:') !== false) {
            return ['ok' => false, 'error' => trim($output)];
        }

        return ['ok' => true, 'detail' => trim($output)];
    }

    private static function resolveConfigPath(string $catalogRoot): ?string
    {
        $candidates = [
            $catalogRoot . '/deploy/config.php',
        ];
        $envOverride = getenv('NUMINIX_GIT_SYNC_CONFIG');
        if (is_string($envOverride) && $envOverride !== '') {
            array_unshift($candidates, $envOverride);
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function gitPushEnabled(string $configPath): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $raw = @shell_exec(
            escapeshellarg($php) . ' -r '
            . escapeshellarg(
                '$c = require ' . var_export($configPath, true) . ';'
                . ' echo !empty($c[\'enable_git_push\']) ? \'1\' : \'0\';'
            )
        );
        return trim((string) $raw) === '1';
    }

    /**
     * @return array<string, string>|null
     */
    private static function buildEnv(string $configPath): ?array
    {
        $env = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $env[$k] = (string) $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (!is_string($k) || (!is_string($v) && !is_numeric($v))) {
                continue;
            }
            if (!isset($env[$k])) {
                $env[$k] = (string) $v;
            }
        }
        $env['NUMINIX_GIT_SYNC_CONFIG'] = $configPath;
        return $env;
    }

    private static function runCommand(string $cmd, array $env): ?string
    {
        if (function_exists('proc_open')) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
            if (!is_resource($proc)) {
                return null;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return trim((string) $stdout . "\n" . (string) $stderr);
        }
        if (!function_exists('exec')) {
            return null;
        }
        $lines = [];
        $code = 0;
        @exec($cmd, $lines, $code);
        return implode("\n", $lines);
    }
}

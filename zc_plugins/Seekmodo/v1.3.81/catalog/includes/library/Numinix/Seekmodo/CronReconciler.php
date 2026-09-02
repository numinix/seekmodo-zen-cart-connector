<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Reconcile managed Seekmodo cron blocks on the storefront host.
 *
 * Writes only lines between `# numinix-seekmodo-managed:` markers.
 * Falls back to NUMINIX_SEEKMODO_CRON_NOTICE when the host cannot
 * write system cron files (typical cPanel).
 */
final class CronReconciler
{
    private const MANAGED_MARKER = '# numinix-seekmodo-managed:';

    /**
     * @return array{ok:bool, path?:string, notice?:string}
     */
    public function reconcile(): array
    {
        if (!defined('NUMINIX_SEEKMODO_TENANT_ID') || (string) NUMINIX_SEEKMODO_TENANT_ID === '') {
            return ['ok' => false, 'notice' => 'tenant not paired'];
        }
        if (function_exists('numinix_seekmodo_mode') && numinix_seekmodo_mode() === 'off') {
            return ['ok' => true];
        }
        $schedule = defined('NUMINIX_SEEKMODO_INDEXER_SCHEDULE')
            ? strtolower(trim((string) NUMINIX_SEEKMODO_INDEXER_SCHEDULE))
            : 'daily';
        $offset = defined('NUMINIX_SEEKMODO_INDEXER_CRON_OFFSET_MIN')
            ? max(0, min(59, (int) NUMINIX_SEEKMODO_INDEXER_CRON_OFFSET_MIN))
            : 0;
        $tenantSlug = $this->tenantSlug((string) NUMINIX_SEEKMODO_TENANT_ID);
        $pluginVer = $this->pluginVersionDir();
        if ($pluginVer === '') {
            return ['ok' => false, 'notice' => 'cannot resolve plugin version path'];
        }
        $docroot = rtrim((string) DIR_FS_CATALOG, '/\\');
        $fullCmd = 'cd ' . $docroot . ' && /usr/bin/php '
            . $pluginVer . '/catalog/numinix_seekmodo_push_catalog.php'
            . ' >>' . $this->logPath('indexer') . ' 2>&1';
        $deltaCmd = 'cd ' . $docroot . ' && /usr/bin/php '
            . $pluginVer . '/catalog/numinix_seekmodo_index_delta.php'
            . ' >>' . $this->logPath('delta') . ' 2>&1';
        $user = $this->cronUser();
        $renderScript = $this->renderScriptPath();
        if ($renderScript === '' || !is_file($renderScript)) {
            return ['ok' => false, 'notice' => 'render_indexer_cron.php missing'];
        }
        $escaped = static function (string $s): string {
            return escapeshellarg($s);
        };
        $cmd = 'php ' . $escaped($renderScript)
            . ' ' . $escaped($schedule)
            . ' ' . $escaped($fullCmd)
            . ' ' . $escaped($user)
            . ' ' . (string) $offset
            . ' ' . $escaped($deltaCmd);
        $rendered = shell_exec($cmd);
        if (!is_string($rendered) || trim($rendered) === '') {
            return ['ok' => false, 'notice' => 'cron render failed'];
        }
        $managedBlock = trim($rendered) . "\n";
        $target = '/etc/cron.d/numinix-seekmodo-' . $tenantSlug;
        $written = $this->writeManagedCron($target, $managedBlock);
        if ($written) {
            $this->clearCronNotice();
            return ['ok' => true, 'path' => $target];
        }
        $notice = "Install Seekmodo cron (copy into cPanel or {$target}):\n" . $managedBlock;
        $this->storeCronNotice($notice);
        return ['ok' => false, 'notice' => $notice];
    }

    private function tenantSlug(string $tenantId): string
    {
        $slug = preg_replace('/[^a-z0-9_-]+/i', '-', $tenantId) ?? 'tenant';
        return strtolower(trim($slug, '-'));
    }

    private function pluginVersionDir(): string
    {
        $base = realpath(__DIR__ . '/../../../../');
        if ($base === false) {
            return '';
        }
        return str_replace('\\', '/', $base);
    }

    private function logPath(string $kind): string
    {
        if (defined('DIR_FS_LOGS') && is_string(DIR_FS_LOGS) && DIR_FS_LOGS !== '') {
            return rtrim((string) DIR_FS_LOGS, '/\\') . '/numinix_seekmodo_' . $kind . '.log';
        }
        return '/tmp/numinix_seekmodo_' . $kind . '.log';
    }

    private function cronUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name']) && $info['name'] !== '') {
                return (string) $info['name'];
            }
        }
        return 'www-data';
    }

    private function renderScriptPath(): string
    {
        $path = __DIR__ . '/../../../tools/render_indexer_cron.php';
        return is_file($path) ? $path : '';
    }

    private function writeManagedCron(string $path, string $managedBlock): bool
    {
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $newBody = $this->mergeManagedBlock($existing, $managedBlock);
        if ($newBody === $existing) {
            return is_file($path);
        }
        if (@file_put_contents($path, $newBody, LOCK_EX) === false) {
            return false;
        }
        return true;
    }

    private function mergeManagedBlock(string $existing, string $managedBlock): string
    {
        $lines = $existing === '' ? [] : preg_split("/\r\n|\n|\r/", $existing);
        if (!is_array($lines)) {
            $lines = [];
        }
        $out = [];
        $skipping = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, self::MANAGED_MARKER)) {
                $skipping = true;
                continue;
            }
            if ($skipping) {
                if ($line === '' || str_starts_with($line, '#')) {
                    if ($line === '') {
                        $skipping = false;
                    }
                    continue;
                }
                if (preg_match('/^\S+\s+\S+\s+/', $line) === 1) {
                    continue;
                }
                $skipping = false;
            }
            $out[] = $line;
        }
        if ($out !== [] && end($out) !== '') {
            $out[] = '';
        }
        $managedLines = preg_split("/\r\n|\n|\r/", trim($managedBlock));
        if (is_array($managedLines)) {
            foreach ($managedLines as $ml) {
                $out[] = $ml;
            }
        }
        $out[] = '';
        return implode("\n", $out);
    }

    private function storeCronNotice(string $notice): void
    {
        if (!isset($GLOBALS['db']) || !defined('TABLE_CONFIGURATION')) {
            return;
        }
        $key = 'NUMINIX_SEEKMODO_CRON_NOTICE';
        $val = zen_db_input($notice);
        try {
            $GLOBALS['db']->Execute(
                'UPDATE ' . TABLE_CONFIGURATION
                . " SET configuration_value = '{$val}', last_modified = NOW()"
                . " WHERE configuration_key = '{$key}'"
            );
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private function clearCronNotice(): void
    {
        if (!isset($GLOBALS['db']) || !defined('TABLE_CONFIGURATION')) {
            return;
        }
        try {
            $GLOBALS['db']->Execute(
                'UPDATE ' . TABLE_CONFIGURATION
                . " SET configuration_value = '', last_modified = NOW()"
                . " WHERE configuration_key = 'NUMINIX_SEEKMODO_CRON_NOTICE'"
            );
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}

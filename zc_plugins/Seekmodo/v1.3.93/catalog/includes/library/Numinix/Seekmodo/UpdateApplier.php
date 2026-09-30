<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Sprint 4 PR 4: one-click apply + rollback.
 *
 * Companion to UpdateClient.  Handles the on-disk side of the
 * upgrade:
 *
 *   1. Download the signed zip via UpdateClient::downloadZip().
 *   2. Re-verify SHA-256 + ed25519 against the bundled public key
 *      (UpdateClient::verifySignature()).
 *   3. Snapshot the current `zc_plugins/Seekmodo/<oldver>/` tree to
 *      a sibling `.backup-<oldver>/` directory (idempotent — if a
 *      backup with the same name already exists, the old one is
 *      pruned first, mirroring the behaviour described in
 *      PROJECT_PLAN.md §0.7 Sprint 4 PR 4).
 *   4. Expand the new tree at `zc_plugins/Seekmodo/v<X.Y.Z>/`.
 *   5. Run the new version's `ScriptedInstaller::executeUpgrade()`
 *      (or `executeInstall()` if the upgrade entry-point is absent
 *      on this version, matching Zen Cart Plugin Manager semantics).
 *
 * Rollback swaps the live tree with one of the .backup-<vN>/ dirs.
 *
 * Backup retention: the most recent 3 backups are kept; older ones
 * are pruned at the end of every successful apply().  Older
 * backups can be archived elsewhere by the operator before that
 * happens.
 */
final class UpdateApplier
{
    private const BACKUP_PREFIX = '.backup-';
    private const KEEP_BACKUPS = 3;

    private string $pluginRoot;
    private string $currentVersionDir;
    private UpdateClient $client;

    public function __construct(string $pluginRoot, string $currentVersionDir, UpdateClient $client = null)
    {
        $this->pluginRoot = rtrim($pluginRoot, '/\\');
        $this->currentVersionDir = rtrim($currentVersionDir, '/\\');
        $this->client = $client ?? new UpdateClient($currentVersionDir);
    }

    public static function fromRunningPlugin(): self
    {
        $versionDir = realpath(__DIR__ . '/../../../../../') ?: '';
        $pluginRoot = realpath($versionDir . DIRECTORY_SEPARATOR . '..') ?: dirname($versionDir);
        return new self($pluginRoot, $versionDir);
    }

    /**
     * @param array<string, mixed> $entry  manifest.json platforms.zen_cart entry
     * @return array{ok: bool, error?: string, backup_dir?: string, new_version_dir?: string}
     */
    public function apply(array $entry): array
    {
        if (!isset($entry['url'], $entry['latest'])) {
            return ['ok' => false, 'error' => 'manifest entry missing url or latest'];
        }
        $url = (string)$entry['url'];
        $latest = 'v' . ltrim((string)$entry['latest'], 'v');
        if (UpdateClient::parseVersion($latest) === null) {
            return ['ok' => false, 'error' => 'manifest latest is not a valid v<x.y.z>: ' . $latest];
        }

        $zipPath = $this->client->downloadZip($url);
        if ($zipPath === null) {
            return ['ok' => false, 'error' => 'download failed for ' . $url];
        }
        try {
            $verifyError = $this->client->verifySignature($zipPath, $entry);
            if ($verifyError !== null) {
                return ['ok' => false, 'error' => $verifyError];
            }

            $stagingDir = $this->makeStagingDir();
            if ($stagingDir === null) {
                return ['ok' => false, 'error' => 'unable to create staging dir under ' . sys_get_temp_dir()];
            }
            try {
                if (!$this->extractZip($zipPath, $stagingDir)) {
                    return ['ok' => false, 'error' => 'unable to expand zip into staging dir'];
                }

                $stagedVersionTree = $this->locateVersionTree($stagingDir, $latest);
                if ($stagedVersionTree === null) {
                    return ['ok' => false, 'error' => 'staged zip did not contain zc_plugins/Seekmodo/' . $latest];
                }

                $oldVersion = basename($this->currentVersionDir);
                $backupDir = $this->backupCurrentTree($oldVersion);
                if ($backupDir === null) {
                    return ['ok' => false, 'error' => 'unable to back up current tree'];
                }

                $newVersionDir = $this->pluginRoot . DIRECTORY_SEPARATOR . $latest;
                if (is_dir($newVersionDir)) {
                    $this->rrmdir($newVersionDir);
                }
                if (!@rename($stagedVersionTree, $newVersionDir)) {
                    if (!$this->rcopy($stagedVersionTree, $newVersionDir)) {
                        return ['ok' => false, 'error' => 'unable to install new version into ' . $newVersionDir];
                    }
                }

                $this->runUpgrade($newVersionDir);
                $this->pruneBackups();

                $gitSync = GitSyncTrigger::maybeRunAfterConnectorUpdate($oldVersion, $latest);

                return [
                    'ok' => true,
                    'backup_dir' => $backupDir,
                    'new_version_dir' => $newVersionDir,
                    'git_sync' => $gitSync,
                ];
            } finally {
                $this->rrmdir($stagingDir);
            }
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function rollback(string $targetVersion): array
    {
        $target = 'v' . ltrim($targetVersion, 'v');
        if (UpdateClient::parseVersion($target) === null) {
            return ['ok' => false, 'error' => 'invalid target version: ' . $targetVersion];
        }
        $backup = $this->pluginRoot . DIRECTORY_SEPARATOR . self::BACKUP_PREFIX . $target;
        if (!is_dir($backup)) {
            return ['ok' => false, 'error' => 'no backup directory for ' . $target];
        }
        $live = $this->pluginRoot . DIRECTORY_SEPARATOR . $target;
        if (is_dir($live)) {
            $this->rrmdir($live);
        }
        if (!$this->rcopy($backup, $live)) {
            return ['ok' => false, 'error' => 'rollback copy failed'];
        }
        $this->runUpgrade($live);
        return ['ok' => true];
    }

    /**
     * @return list<array{version: string, mtime: int}>
     */
    public function listBackups(): array
    {
        $out = [];
        $dh = @opendir($this->pluginRoot);
        if ($dh === false) {
            return $out;
        }
        while (($entry = readdir($dh)) !== false) {
            if (strncmp($entry, self::BACKUP_PREFIX, strlen(self::BACKUP_PREFIX)) !== 0) {
                continue;
            }
            $version = substr($entry, strlen(self::BACKUP_PREFIX));
            if (UpdateClient::parseVersion($version) === null) {
                continue;
            }
            $path = $this->pluginRoot . DIRECTORY_SEPARATOR . $entry;
            $out[] = ['version' => $version, 'mtime' => (int)filemtime($path)];
        }
        closedir($dh);
        usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    private function makeStagingDir(): ?string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'numinix_seekmodo_apply_' . bin2hex(random_bytes(6));
        return @mkdir($dir, 0775, true) ? $dir : null;
    }

    private function extractZip(string $zipPath, string $dest): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $z = new \ZipArchive();
        if ($z->open($zipPath) !== true) {
            return false;
        }
        $ok = $z->extractTo($dest);
        $z->close();
        return (bool)$ok;
    }

    private function locateVersionTree(string $stagingDir, string $version): ?string
    {
        $candidate = $stagingDir . DIRECTORY_SEPARATOR . 'zc_plugins' . DIRECTORY_SEPARATOR . 'Seekmodo' . DIRECTORY_SEPARATOR . $version;
        return is_dir($candidate) ? $candidate : null;
    }

    private function backupCurrentTree(string $oldVersion): ?string
    {
        $backupDir = $this->pluginRoot . DIRECTORY_SEPARATOR . self::BACKUP_PREFIX . $oldVersion;
        if (is_dir($backupDir)) {
            $this->rrmdir($backupDir);
        }
        if (!@mkdir($backupDir, 0775, true)) {
            return null;
        }
        return $this->rcopy($this->currentVersionDir, $backupDir) ? $backupDir : null;
    }

    private function pruneBackups(): void
    {
        $backups = $this->listBackups();
        if (count($backups) <= self::KEEP_BACKUPS) {
            return;
        }
        $toPrune = array_slice($backups, self::KEEP_BACKUPS);
        foreach ($toPrune as $b) {
            $path = $this->pluginRoot . DIRECTORY_SEPARATOR . self::BACKUP_PREFIX . (string)$b['version'];
            $this->rrmdir($path);
        }
    }

    private function runUpgrade(string $versionDir): void
    {
        $installerPath = $versionDir . DIRECTORY_SEPARATOR . 'Installer' . DIRECTORY_SEPARATOR . 'ScriptedInstaller.php';
        if (!is_file($installerPath)) {
            return;
        }
        // ScriptedInstaller is loaded by the Zen Cart Plugin Manager
        // with $pluginManager / $dbConn set up.  When run from the
        // admin Updates page, the plugin's already-active state means
        // we cannot safely invoke the installer's full lifecycle from
        // here; instead we leave a sentinel file that the next admin
        // page-load picks up via init_numinix_seekmodo.php and runs
        // the upgrade entry-point under the proper Zen Cart globals.
        // This is the same indirection the legacy
        // tools/install_redline_connector.py used and matches Zen Cart
        // 1.5.8's expectation that Plugin Manager owns the install
        // lifecycle.
        @file_put_contents(
            $this->pluginRoot . DIRECTORY_SEPARATOR . '.pending-upgrade',
            basename($versionDir) . "\n",
            LOCK_EX
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isDir()) {
                @rmdir((string)$f->getRealPath());
            } else {
                @unlink((string)$f->getRealPath());
            }
        }
        @rmdir($dir);
    }

    private function rcopy(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        if (!is_dir($dst) && !@mkdir($dst, 0775, true)) {
            return false;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $f) {
            $target = $dst . DIRECTORY_SEPARATOR . $iter->getSubPathName();
            if ($f->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true)) {
                    return false;
                }
            } else {
                if (!@copy((string)$f->getRealPath(), $target)) {
                    return false;
                }
            }
        }
        return true;
    }
}

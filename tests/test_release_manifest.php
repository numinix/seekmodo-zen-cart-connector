<?php
declare(strict_types=1);

$files = glob(__DIR__ . '/../zc_plugins/Seekmodo/v*/manifest.php');
usort($files, static fn (string $a, string $b): int => version_compare(basename(dirname($a)), basename(dirname($b))));
$manifest = require end($files);
if (strpos($manifest['changelog'], $manifest['pluginVersion'] . ' ') !== 0) {
    throw new RuntimeException('Plugin Manager release notes must describe the current connector version');
}
echo "PASS: current connector version and Plugin Manager release notes agree\n";

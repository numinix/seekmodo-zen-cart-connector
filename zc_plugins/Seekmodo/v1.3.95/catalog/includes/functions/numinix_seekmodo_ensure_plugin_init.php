<?php
/**
 * Ensure catalog-side Seekmodo init has run (autoloader + helpers).
 *
 * Zen Cart Plugin Manager merges plugin auto_loaders into the
 * storefront when the plugin is installed with infs=1. On some
 * Zen Cart 1.5.7 installs (and whenever only catalog-root shims were
 * copied by hand without a successful Install/Upgrade), application_top
 * never loads init_numinix_seekmodo.php, so Pairing / Client classes
 * and procedural helpers are missing and pair_callback / suggest return
 * errors or connector_unavailable.
 *
 * Catalog-root shims call this after requiring application_top. Idempotent.
 */

declare(strict_types=1);

if (!function_exists('numinix_seekmodo_ensure_plugin_init')) {
    /**
     * @return bool True when Seekmodo catalog init is available after this call.
     */
    function numinix_seekmodo_ensure_plugin_init(): bool
    {
        if (function_exists('numinix_seekmodo_enabled')) {
            return true;
        }

        $catalogRoot = '';
        if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
            $catalogRoot = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/';
        } else {
            // Fallback: this file lives at
            // zc_plugins/Seekmodo/vX/catalog/includes/functions/
            $here = str_replace('\\', '/', __DIR__);
            if (preg_match('#^(.*?)/zc_plugins/Seekmodo/v[^/]+/catalog/includes/functions$#', $here, $m)) {
                $catalogRoot = $m[1] . '/';
            }
        }

        if ($catalogRoot === '') {
            return false;
        }

        $pluginRoot = $catalogRoot . 'zc_plugins/Seekmodo/';
        $initFiles = glob($pluginRoot . 'v*/catalog/includes/init_includes/init_numinix_seekmodo.php') ?: [];
        if ($initFiles === []) {
            return false;
        }

        usort($initFiles, 'strnatcmp');
        $initFiles = array_reverse($initFiles);
        foreach ($initFiles as $initFile) {
            if (is_file($initFile)) {
                require_once $initFile;
                break;
            }
        }

        return function_exists('numinix_seekmodo_enabled');
    }
}

if (!function_exists('numinix_seekmodo_require_ensure_helper')) {
    /**
     * Locate and load this helper from zc_plugins when the calling shim
     * only exists as a flat copy under the catalog root.
     */
    function numinix_seekmodo_require_ensure_helper(): void
    {
        if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
            return;
        }

        $catalogRoot = '';
        if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
            $catalogRoot = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/';
        } else {
            $cwd = str_replace('\\', '/', (string) getcwd());
            if ($cwd !== '' && is_dir($cwd . '/zc_plugins/Seekmodo')) {
                $catalogRoot = rtrim($cwd, '/') . '/';
            }
        }

        if ($catalogRoot === '') {
            return;
        }

        $helpers = glob(
            $catalogRoot . 'zc_plugins/Seekmodo/v*/catalog/includes/functions/numinix_seekmodo_ensure_plugin_init.php'
        ) ?: [];
        usort($helpers, 'strnatcmp');
        $helpers = array_reverse($helpers);
        foreach ($helpers as $helper) {
            if (is_file($helper)) {
                require_once $helper;
                break;
            }
        }
    }
}

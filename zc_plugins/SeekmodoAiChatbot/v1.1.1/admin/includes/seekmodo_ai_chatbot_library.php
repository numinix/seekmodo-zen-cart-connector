<?php
/**
 * Resolve SeekmodoAiChatbot library directory for admin pages.
 *
 * Admin scripts are copied into the store admin folder, so relative paths like
 * dirname(__DIR__) . '/catalog/includes/library/...' resolve to a non-existent
 * catalog/ tree. Prefer the highest zc_plugins version under DIR_FS_CATALOG.
 */

if (!function_exists('seekmodo_ai_chatbot_resolve_admin_includes_dir')) {
    /**
     * Highest-version plugin admin/includes dir (survives deploy wipe of copied admin files).
     *
     * @return string Absolute path or '' if not found.
     */
    function seekmodo_ai_chatbot_resolve_admin_includes_dir(): string
    {
        $candidates = [];
        if (defined('DIR_FS_CATALOG')) {
            $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
            foreach (glob($catalog . '/zc_plugins/SeekmodoAiChatbot/v*/admin/includes', GLOB_ONLYDIR) ?: [] as $dir) {
                $candidates[] = $dir;
            }
        }
        // When this helper itself lives under zc_plugins/.../admin/includes
        $here = __DIR__;
        if (preg_match('#[/\\\\]SeekmodoAiChatbot[/\\\\]v[^/\\\\]+[/\\\\]admin[/\\\\]includes$#', $here)) {
            $candidates[] = $here;
        }

        usort($candidates, static function ($a, $b) {
            $va = '0';
            $vb = '0';
            if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', (string) $a), $m)) {
                $va = $m[1];
            }
            if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', (string) $b), $m)) {
                $vb = $m[1];
            }
            return version_compare($vb, $va);
        });

        foreach ($candidates as $dir) {
            if (is_dir($dir) && is_file($dir . '/seekmodo_ai_chatbot_library.php')) {
                return $dir;
            }
        }

        return '';
    }

    /**
     * Require an admin include from the copied admin tree or the plugin tree.
     *
     * @param string $relative Path under admin/includes (e.g. languages/english/foo.php).
     */
    function seekmodo_ai_chatbot_require_admin_include(string $relative): void
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $candidates = [];

        if (defined('DIR_FS_ADMIN')) {
            $candidates[] = rtrim((string) DIR_FS_ADMIN, '/\\') . '/includes/' . $relative;
        }
        $candidates[] = __DIR__ . '/' . $relative;

        $pluginIncludes = seekmodo_ai_chatbot_resolve_admin_includes_dir();
        if ($pluginIncludes !== '') {
            $candidates[] = $pluginIncludes . '/' . $relative;
        }

        if (defined('DIR_FS_CATALOG')) {
            $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
            foreach (glob($catalog . '/zc_plugins/SeekmodoAiChatbot/v*/admin/includes/' . $relative) ?: [] as $path) {
                $candidates[] = $path;
            }
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                return;
            }
        }

        throw new RuntimeException('Missing Seekmodo AI Chatbot admin include: ' . $relative);
    }
}

if (!function_exists('seekmodo_ai_chatbot_resolve_library_dir')) {
    /**
     * @return string Absolute path to .../library/SeekmodoAiChatbot, or '' if not found.
     */
    function seekmodo_ai_chatbot_resolve_library_dir(): string
    {
        $candidates = [];

        if (defined('DIR_FS_CATALOG')) {
            $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
            foreach (glob($catalog . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot', GLOB_ONLYDIR) ?: [] as $dir) {
                $candidates[] = $dir;
            }
            $candidates[] = $catalog . '/includes/library/SeekmodoAiChatbot';
            $candidates[] = $catalog . '/catalog/includes/library/SeekmodoAiChatbot';
        }

        // When this file lives under zc_plugins/.../admin/includes/, climb to the version root.
        $fromPluginVersion = dirname(__DIR__, 2) . '/catalog/includes/library/SeekmodoAiChatbot';
        $candidates[] = $fromPluginVersion;

        usort($candidates, static function ($a, $b) {
            $va = '0';
            $vb = '0';
            if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', (string) $a, $m)) {
                $va = $m[1];
            }
            if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', (string) $b, $m)) {
                $vb = $m[1];
            }
            return version_compare($vb, $va);
        });

        foreach ($candidates as $dir) {
            if (is_dir($dir) && is_file($dir . '/ChatArchive.php')) {
                return $dir;
            }
        }

        return '';
    }

    /**
     * Require one or more library PHP files from the resolved plugin library.
     *
     * @param list<string> $files Filenames relative to the library directory.
     * @param bool $required When true, throw RuntimeException if the library or a file is missing.
     */
    function seekmodo_ai_chatbot_require_library(array $files, bool $required = true): string
    {
        $lib = seekmodo_ai_chatbot_resolve_library_dir();
        if ($lib === '') {
            if ($required) {
                throw new RuntimeException('SeekmodoAiChatbot library directory not found under zc_plugins.');
            }
            return '';
        }

        foreach ($files as $file) {
            $path = $lib . '/' . ltrim((string) $file, '/\\');
            if (!is_file($path)) {
                if ($required) {
                    throw new RuntimeException('Missing SeekmodoAiChatbot library file: ' . $file);
                }
                continue;
            }
            require_once $path;
        }

        return $lib;
    }
}

if (!function_exists('seekmodo_ai_chatbot_admin_asset_url')) {
    /**
     * URL for an admin asset under the plugin admin tree or the copied admin folder.
     *
     * @param string $relativePath Path relative to the admin page root (e.g. includes/css/foo.css).
     */
    function seekmodo_ai_chatbot_admin_asset_url(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        $adminRoots = [];
        if (defined('DIR_FS_ADMIN') && DIR_FS_ADMIN !== '') {
            $adminRoots[] = rtrim((string) DIR_FS_ADMIN, '/\\');
        }
        $includesDir = function_exists('seekmodo_ai_chatbot_resolve_admin_includes_dir')
            ? seekmodo_ai_chatbot_resolve_admin_includes_dir()
            : '';
        if ($includesDir !== '') {
            $adminRoots[] = dirname($includesDir);
        }

        $fsFile = '';
        $adminFs = $adminRoots[0] ?? '';
        foreach ($adminRoots as $root) {
            $candidate = $root . '/' . $relativePath;
            if (is_file($candidate)) {
                $fsFile = $candidate;
                $adminFs = $root;
                break;
            }
        }
        if ($fsFile === '' && $adminFs !== '') {
            $fsFile = $adminFs . '/' . $relativePath;
        }

        $baseUrl = '';
        if ($adminFs !== '' && defined('DIR_FS_CATALOG') && defined('DIR_WS_CATALOG')) {
            $fsCatalog = str_replace('\\', '/', rtrim((string) DIR_FS_CATALOG, '/\\'));
            $dirNorm = str_replace('\\', '/', $adminFs);
            if (strpos($dirNorm, $fsCatalog) === 0) {
                $baseUrl = rtrim((string) DIR_WS_CATALOG, '/') . substr($dirNorm, strlen($fsCatalog)) . '/';
            }
        }
        if ($baseUrl === '' && $includesDir !== '') {
            $pluginAdmin = dirname($includesDir);
            $ver = basename(dirname($pluginAdmin));
            $baseUrl = '../zc_plugins/SeekmodoAiChatbot/' . $ver . '/admin/';
        }

        $url = rtrim($baseUrl, '/') . '/' . $relativePath;
        if ($fsFile !== '' && is_file($fsFile)) {
            $url .= '?v=' . filemtime($fsFile);
        }
        return $url;
    }
}

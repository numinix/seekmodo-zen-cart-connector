<?php
/**
 * Seekmodo — admin Tools menu registration (self-healing).
 *
 * Zen Cart 1.5.7 exposes zen_register_admin_page() (singular); 1.5.8+
 * plugin manager adds zen_register_admin_pages() (plural). Earlier
 * connector releases only called the plural API, so installs on 1.5.7
 * that never completed Plugin Manager → Install silently missed the
 * admin_pages rows and "Connect to Seekmodo" never appeared under Tools.
 *
 * Loaded on every admin request via extra_configures/numinix_seekmodo_bootstrap.php
 * and invoked from ScriptedInstaller::ensureAdminPage() during install/upgrade.
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!function_exists('numinix_seekmodo_ensure_admin_pages')) {
    function numinix_seekmodo_ensure_admin_pages(): void
    {
        if (!defined('FILENAME_NUMINIX_SEEKMODO_CONNECT')) {
            define('FILENAME_NUMINIX_SEEKMODO_CONNECT', 'numinix_seekmodo_connect');
        }
        if (!defined('BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT')) {
            define('BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT', 'Connect to Seekmodo');
        }
        if (!defined('FILENAME_NUMINIX_SEEKMODO_UPDATES')) {
            define('FILENAME_NUMINIX_SEEKMODO_UPDATES', 'numinix_seekmodo_updates');
        }
        if (!defined('BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES')) {
            define('BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES', 'Seekmodo Updates');
        }

        $pages = [
            [
                'page_key' => 'numinixSeekmodoConnect',
                'language_key' => 'BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT',
                'main_page' => 'FILENAME_NUMINIX_SEEKMODO_CONNECT',
                'page_params' => '',
                'menu_key' => 'tools',
                'display_on_menu' => 'Y',
                'sort_order' => 500,
            ],
            [
                'page_key' => 'numinixSeekmodoUpdates',
                'language_key' => 'BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES',
                'main_page' => 'FILENAME_NUMINIX_SEEKMODO_UPDATES',
                'page_params' => '',
                'menu_key' => 'tools',
                'display_on_menu' => 'Y',
                'sort_order' => 510,
            ],
        ];

        if (function_exists('zen_register_admin_pages')) {
            foreach ($pages as $page) {
                zen_register_admin_pages(
                    $page['page_key'],
                    $page['language_key'],
                    $page['main_page'],
                    $page['page_params'],
                    $page['menu_key'],
                    $page['display_on_menu'],
                    $page['sort_order']
                );
            }
            return;
        }

        if (!function_exists('zen_register_admin_page')) {
            return;
        }

        foreach ($pages as $page) {
            if (function_exists('zen_page_key_exists') && zen_page_key_exists($page['page_key'])) {
                continue;
            }
            zen_register_admin_page(
                $page['page_key'],
                $page['language_key'],
                $page['main_page'],
                $page['page_params'],
                $page['menu_key'],
                $page['display_on_menu'],
                $page['sort_order']
            );
        }
    }
}

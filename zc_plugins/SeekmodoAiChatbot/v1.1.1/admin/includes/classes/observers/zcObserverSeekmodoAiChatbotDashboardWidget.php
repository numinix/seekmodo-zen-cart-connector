<?php
/**
 * Registers Support Tickets on Admin Home when the dashboard uses
 * NOTIFY_ADMIN_DASHBOARD_WIDGETS (avoids string-patching index_dashboard.php).
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Admin copies + zc_plugins may both include this file; declare once.
if (!class_exists('zcObserverSeekmodoAiChatbotDashboardWidget', false)) {
class zcObserverSeekmodoAiChatbotDashboardWidget extends base
{
    public function __construct()
    {
        $this->attach($this, ['NOTIFY_ADMIN_DASHBOARD_WIDGETS']);
    }

    /**
     * @param object $callingClass
     * @param string $eventID
     * @param mixed $defaultColumns
     * @param mixed $columns
     * @param mixed $files
     */
    public function update(&$callingClass, $eventID, $defaultColumns = null, &$columns = null, &$files = null)
    {
        if ($eventID !== 'NOTIFY_ADMIN_DASHBOARD_WIDGETS' || !is_array($columns) || !is_array($files)) {
            return;
        }

        $rel = DIR_WS_MODULES . 'dashboard_widgets/SupportTicketsDashboardWidget.php';
        if (!isset($files['SupportTickets'])) {
            // Prefer admin copy; fall back to plugin path when present on disk.
            if (is_file($rel)) {
                $files['SupportTickets'] = $rel;
            } else {
                $pluginWidget = $this->resolvePluginWidgetPath();
                if ($pluginWidget !== null) {
                    $files['SupportTickets'] = $pluginWidget;
                } else {
                    return;
                }
            }
        }

        $already = false;
        foreach ($columns as $list) {
            if (!is_array($list)) {
                continue;
            }
            if (in_array('SupportTickets', $list, true)) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            if (!isset($columns['colone']) || !is_array($columns['colone'])) {
                $columns['colone'] = [];
            }
            $columns['colone'][] = 'SupportTickets';
        }
    }

    private function resolvePluginWidgetPath(): ?string
    {
        if (!defined('DIR_FS_CATALOG')) {
            return null;
        }
        $base = rtrim((string) DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot';
        $dirs = glob($base . '/v*/admin/includes/modules/dashboard_widgets', GLOB_ONLYDIR) ?: [];
        usort($dirs, static function ($a, $b) {
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
        foreach ($dirs as $dir) {
            $file = $dir . '/SupportTicketsDashboardWidget.php';
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }
}
}

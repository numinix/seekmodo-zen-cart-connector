-- Numinix Seekmodo connector — TABLE_CONFIGURATION rows.
--
-- This is a documentation-only mirror of what
-- zc_plugins/Seekmodo/v1.0.0/Installer/ScriptedInstaller.php
-- writes when an operator clicks "Install" in Admin -> Plugin Manager.
--
-- Use it for:
--   - emergency manual install on a host that can't run the scripted
--     installer (e.g. plugin manager broken)
--   - schema review (read-only inspection of what the plugin lays down)
--   - verifying state in tools/install_redline_connector.py after a deploy
--
-- The installer is the source of truth; if there's drift, treat the
-- installer as canonical and update this file.
--
-- Group ID is auto-allocated at install time (look up by title
-- 'Seekmodo Search' in TABLE_CONFIGURATION_GROUP). Replace
-- @group_id below before running by hand.

-- SET @group_id = (SELECT configuration_group_id FROM configuration_group WHERE configuration_group_title = 'Seekmodo Search');

INSERT IGNORE INTO configuration
    (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
VALUES
    ('Seekmodo: Gateway URL', 'NUMINIX_SEEKMODO_URL', 'https://mcp.seekmodo.com',
     'Base URL of the Seekmodo MCP gateway. The connector appends <code>/v1/...</code> for REST calls.',
     @group_id, 100, NULL, NOW()),
    ('Seekmodo: Tenant ID', 'NUMINIX_SEEKMODO_TENANT_ID', '',
     'Per-store tenant identifier issued by services/mcp-gateway/ops/tenant-add.php.',
     @group_id, 101, NULL, NOW()),
    ('Seekmodo: Shared Secret', 'NUMINIX_SEEKMODO_SHARED_SECRET', '',
     'Per-store HMAC key (64-hex). Captured once at tenant-add time.',
     @group_id, 102, NULL, NOW()),
    ('Seekmodo: Mode', 'NUMINIX_SEEKMODO_MODE', 'off',
     'One of <b>off</b> | <b>shadow</b> | <b>enforce</b>.',
     @group_id, 103, 'zen_cfg_select_option(array(\'off\', \'shadow\', \'enforce\'),', NOW()),
    ('Seekmodo: Hot-Path Timeout (ms)', 'NUMINIX_SEEKMODO_TIMEOUT_MS', '250',
     'Per-call hot-path timeout in milliseconds. Default <b>250</b>; valid range 80&ndash;2000.',
     @group_id, 104, NULL, NOW()),
    ('Seekmodo: Index Batch Size', 'NUMINIX_SEEKMODO_INDEX_BATCH', '500',
     'Max documents per /v1/index call. Gateway accepts up to 1000; we leave headroom.',
     @group_id, 105, NULL, NOW()),
    ('Seekmodo: Debug Logging', 'NUMINIX_SEEKMODO_DEBUG', 'false',
     'When <b>true</b>, logs every hot-path call to <code>logs/numinix_seekmodo.log</code>.',
     @group_id, 106, 'zen_cfg_select_option(array(\'true\', \'false\'),', NOW());

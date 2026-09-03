<?php
/**
 * Seekmodo Search connector — scripted installer.
 *
 * Creates the NUMINIX_SEEKMODO_* configuration rows on install. MODE
 * defaults to `active` so the connector self-manages (auto-promotion
 * FSM); operators can override per-tenant from admin.seekmodo.com.
 * The whole "Seekmodo Search" configuration group is hidden from the
 * Zen Cart admin (CONFIGURATION_GROUP_VISIBLE_<id>=false) — these
 * rows act as a runtime cache for values pulled from the gateway, not
 * as the editable surface.
 *
 * Idempotent: re-running install with rows already present is a
 * no-op (Zen Cart's addConfigurationKey honors INSERT IGNORE).
 */

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    private const GROUP_TITLE = 'Seekmodo Search';
    private const GROUP_TITLE_LEGACY = 'Numinix Seekmodo';

    protected function executeInstall()
    {
        $this->renameLegacyGroup();
        $groupId = $this->ensureGroup();
        $this->ensureAdminPage();
        $this->hideGroupFromAdmin($groupId);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_URL', [
            'configuration_title' => 'Seekmodo: Gateway URL',
            'configuration_value' => 'https://mcp.seekmodo.com',
            'configuration_description' => 'Base URL of the Seekmodo MCP gateway. The connector appends <code>/v1/...</code> for REST calls. Leave at the default unless you are pointing at a staging gateway.',
            'configuration_group_id' => $groupId,
            'sort_order' => 100,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_TENANT_ID', [
            'configuration_title' => 'Seekmodo: Tenant ID',
            'configuration_value' => '',
            'configuration_description' => 'Per-store tenant identifier issued by <code>services/mcp-gateway/ops/tenant-add.php</code> on the gateway side.',
            'configuration_group_id' => $groupId,
            'sort_order' => 101,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_SHARED_SECRET', [
            'configuration_title' => 'Seekmodo: Shared Secret',
            'configuration_value' => '',
            'configuration_description' => 'Per-store HMAC key (64-hex). Captured once at tenant-add time on the gateway; never stored anywhere recoverable.',
            'configuration_group_id' => $groupId,
            'sort_order' => 102,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_MODE', [
            'configuration_title' => 'Seekmodo: Mode',
            'configuration_value' => 'active',
            'configuration_description' => 'Managed at admin.seekmodo.com. One of <b>off</b> | <b>active</b> | <b>shadow</b> | <b>enforce</b>.<br><b>off</b> = bypass Seekmodo, fall back to native Zen Cart search.<br><b>active</b> (recommended) = let the connector self-manage. It begins in shadow, observes gateway health for ~24h, then auto-promotes to enforce. If the gateway misbehaves it auto-demotes back to shadow until it heals. No operator intervention required.<br><b>shadow</b> = manual override. Always observation-only; storefront keeps using native search.<br><b>enforce</b> = manual override. Always serve gateway results with native fallback on failure.',
            'configuration_group_id' => $groupId,
            'sort_order' => 103,
            'set_function' => 'zen_cfg_select_option(array(\'off\', \'active\', \'shadow\', \'enforce\'),',
        ]);

        // W6b (v1.0.5+) — tenant-wide default mode the storefront falls
        // back to when MODE is empty. Mirrored from tenant.snapshot's
        // `default_mode` field by RemoteConfig::writeThrough.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_DEFAULT_MODE', [
            'configuration_title' => 'Seekmodo: Default Mode',
            'configuration_value' => 'active',
            'configuration_description' => 'Managed at admin.seekmodo.com. The mode the storefront uses when <b>NUMINIX_SEEKMODO_MODE</b> is empty / unset / invalid. Lets seekmodo.com set a tenant-wide default that survives a manual <b>MODE</b> row clear. One of <b>off</b> | <b>active</b> | <b>shadow</b> | <b>enforce</b>.',
            'configuration_group_id' => $groupId,
            'sort_order' => 107,
            'set_function' => 'zen_cfg_select_option(array(\'off\', \'active\', \'shadow\', \'enforce\'),',
        ]);

        // W6b (v1.0.5+) — declarative cron schedule for the indexer.
        // Mirrored from tenant.snapshot's `indexer_schedule` field by
        // RemoteConfig::writeThrough. The storefront doesn't read this
        // directly; the operator-side install script translates it
        // into a /etc/cron.d/numinix-seekmodo-<tenant> entry via
        // tools/render_indexer_cron.php.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_INDEXER_SCHEDULE', [
            'configuration_title' => 'Seekmodo: Indexer Schedule',
            'configuration_value' => 'daily',
            'configuration_description' => 'Managed at admin.seekmodo.com. Declarative cron schedule for the Seekmodo indexer. One of <b>hourly</b> | <b>every_4h</b> | <b>every_12h</b> | <b>daily</b> | <b>manual</b>. <b>manual</b> means: this connector does not own the cron — the operator runs <code>transfer_products.php</code> on whatever schedule they prefer.',
            'configuration_group_id' => $groupId,
            'sort_order' => 108,
            'set_function' => 'zen_cfg_select_option(array(\'hourly\', \'every_4h\', \'every_12h\', \'daily\', \'manual\'),',
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_AUTO_PROMOTE', [
            'configuration_title' => 'Seekmodo: Auto-Promote',
            'configuration_value' => 'true',
            'configuration_description' => 'Master switch for the auto-promotion state machine, which only runs when MODE=active. <b>true</b> (default) = connector advances bootstrap → shadow → enforce based on observed gateway health, and auto-demotes back to shadow on sustained failures. <b>false</b> = freeze the FSM at its current state (useful while debugging a flaky gateway).',
            'configuration_group_id' => $groupId,
            'sort_order' => 110,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // The next three rows are FSM bookkeeping the connector writes
        // to itself. Keeping them as configuration rows means a human
        // operator can inspect them via Admin -> Configuration -> Seekmodo
        // Search without a separate dashboard.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_AUTO_STATE', [
            'configuration_title' => 'Seekmodo: Auto-Promote State',
            'configuration_value' => 'bootstrap',
            'configuration_description' => 'Read-only FSM phase. One of: <b>bootstrap</b> (just installed; behaves as shadow), <b>shadow_observing</b> (collecting promotion sample), <b>enforced</b> (serving gateway results with native fallback), <b>shadow_recovering</b> (auto-demoted; healing). Written by the connector — do not edit by hand.',
            'configuration_group_id' => $groupId,
            'sort_order' => 111,
        ]);
        $this->addConfigurationKey('NUMINIX_SEEKMODO_AUTO_STATE_SINCE', [
            'configuration_title' => 'Seekmodo: Auto-Promote State Since',
            'configuration_value' => '',
            'configuration_description' => 'ISO-8601 UTC timestamp of the last FSM transition. Read-only.',
            'configuration_group_id' => $groupId,
            'sort_order' => 112,
        ]);
        $this->addConfigurationKey('NUMINIX_SEEKMODO_AUTO_HISTORY', [
            'configuration_title' => 'Seekmodo: Auto-Promote History',
            'configuration_value' => '[]',
            'configuration_description' => 'JSON array of the last 16 FSM transitions: {ts, from, to, reason}. Read-only.',
            'configuration_group_id' => $groupId,
            'sort_order' => 113,
        ]);

        // Legacy single-bucket timeout. Kept for back-compat with
        // older gateway snapshots that don't yet send the split
        // search/index fields. v1.0.13+ Client.php prefers the
        // split-bucket constants below when available and falls
        // back to this only when neither is set.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_TIMEOUT_MS', [
            'configuration_title' => 'Seekmodo: Hot-Path Timeout (ms) — Legacy',
            'configuration_value' => '250',
            'configuration_description' => 'Legacy single-bucket timeout, kept for back-compat with older gateway snapshots. v1.0.13+ uses <code>NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS</code> + <code>NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS</code> instead.',
            'configuration_group_id' => $groupId,
            'sort_order' => 104,
        ]);

        // v1.0.13 — split-bucket timeouts. Search/events runs on
        // the storefront hot path and must never make the page
        // wait; index runs from cron and needs minutes of headroom
        // for a cold Typesense collection upsert. Same
        // RemoteConfig::writeThrough pipeline as everything else.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS', [
            'configuration_title' => 'Seekmodo: Search Timeout (ms)',
            'configuration_value' => '1500',
            'configuration_description' => 'Per-call timeout for /v1/search and /v1/events in milliseconds. Storefront hot path. Default <b>1500</b>; valid range 80&ndash;5000. Lowered values trip the circuit breaker faster on a slow gateway; higher values block the storefront longer before falling back to native LIKE.',
            'configuration_group_id' => $groupId,
            'sort_order' => 1041,
        ]);

        // CAUTION: keep configuration_description text free of the literal
        // substring "NULL" (case-sensitive). Zen Cart's
        // queryFactory::getBindVarValue() rewrites any 'string'-typed bind
        // value matching the regex `/NULL/` to the SQL keyword `null`,
        // which the configuration_description NOT-NULL column then
        // rejects with "Column 'configuration_description' cannot be
        // null" mid-upgrade. (See ZC core
        // includes/classes/db/mysql/query_factory.php case 'string'.)
        $this->addConfigurationKey('NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS', [
            'configuration_title' => 'Seekmodo: Index Timeout (ms)',
            'configuration_value' => '30000',
            'configuration_description' => 'Per-call timeout for /v1/index (catalog upserts) in milliseconds. Cron path. Default <b>30000</b>; valid range 1000&ndash;120000. Bulk Typesense upserts of 500 docs/req routinely take 5&ndash;15s on a cold collection; the historical 250ms shared timeout was too tight and silently dropped indexer writes.',
            'configuration_group_id' => $groupId,
            'sort_order' => 1042,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_INDEX_BATCH', [
            'configuration_title' => 'Seekmodo: Index Batch Size',
            'configuration_value' => '500',
            'configuration_description' => 'Max documents per /v1/index call. The gateway accepts up to 1000 per call; we default to 500 to leave headroom. Lower this if PHP memory/upload limits force smaller batches.',
            'configuration_group_id' => $groupId,
            'sort_order' => 105,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_DEBUG', [
            'configuration_title' => 'Seekmodo: Debug Logging',
            'configuration_value' => 'false',
            'configuration_description' => 'When <b>true</b>, logs every hot-path call to <code>logs/numinix_seekmodo.log</code>. Useful during shadow / enforce verification windows; turn off in steady-state production.',
            'configuration_group_id' => $groupId,
            'sort_order' => 106,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // W6c (v1.0.6+) — bot-check backend selector. Mirrored from
        // tenant.snapshot's `bot_check_backend` field by
        // RemoteConfig::writeThrough. Default stays `'legacy'` so an
        // in-place file deploy of v1.0.6 does NOT change which
        // bot-check service the storefront calls until the operator
        // explicitly opts in via admin.seekmodo.com (Phase B shadow
        // validation). PROJECT_PLAN.md §P1-14.
        $this->addConfigurationKey('NUMINIX_BOT_CHECK_BACKEND', [
            'configuration_title' => 'Seekmodo: Bot-Check Backend',
            'configuration_value' => 'legacy',
            'configuration_description' => 'Managed at admin.seekmodo.com. Selects which bot-check service the storefront calls. <b>legacy</b> (default) = standalone <code>bot-check.numinix.com</code> service. <b>gateway</b> = MCP gateway\'s bundled <code>BotCheck\\*</code> tools at <code>mcp.seekmodo.com/v1/bot.classify</code>. Used during the bot-check consolidation rollout (PROJECT_PLAN.md §P1-14 Phase B). Unrecognised values fall back to <b>legacy</b>.',
            'configuration_group_id' => $groupId,
            'sort_order' => 109,
            'set_function' => 'zen_cfg_select_option(array(\'legacy\', \'gateway\'),',
        ]);

        // Sprint 3 PR 6 (v1.0.14+) — typeahead path toggle. Default
        // <b>false</b> = route typeahead through the gateway's new
        // SuggestTool at <code>/v1/suggest</code> (Sprint 3 PR 3).
        // Flipping to <b>true</b> forces the legacy /v1/search path
        // for the cutover window — useful when the gateway is older
        // than Sprint 3 (no SuggestTool registered) or during a
        // rollback drill. The runtime per-call equivalent is
        // `opts.use_search=true` on a `numinix_seekmodo_run_typeahead`
        // call.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH', [
            'configuration_title' => 'Seekmodo: Typeahead via /v1/search (legacy)',
            'configuration_value' => 'false',
            'configuration_description' => 'When <b>false</b> (default), typeahead routes through the gateway\'s dedicated SuggestTool at <code>/v1/suggest</code> and meters against the <code>searches_suggest</code> bucket. When <b>true</b>, falls back to the v1.0.13 path that posts a slim payload to <code>/v1/search</code> and meters against <code>searches_text</code>. Flip to <b>true</b> for the cutover window if the gateway is older than Sprint 3 (no SuggestTool) or during a rollback drill.',
            'configuration_group_id' => $groupId,
            'sort_order' => 111,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // v1.3.69 — storefront suggest widget. Default is the subscribed
        // `<seekmodo-suggest>` split-rail bundle, not the v1.0.20 legacy
        // dropdown. Empty-corpus / 502 recovery scripts previously
        // stamped USE_LEGACY=true in the configuration table; that
        // leftover is why some paired stores never got the UI that
        // Seekmodo already ships elsewhere. Installer default is false;
        // executeUpgrade also resets leftover true so a Plugin Manager
        // upgrade restores the default without a manual SQL flip.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_SUGGEST_ENABLED', [
            'configuration_title' => 'Seekmodo: Storefront Suggest',
            'configuration_value' => 'true',
            'configuration_description' => 'When <b>true</b> (default), the connector injects the Seekmodo suggest widget on the storefront search box. Set to <b>false</b> to suppress the widget site-wide.',
            'configuration_group_id' => $groupId,
            'sort_order' => 1111,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);
        $this->addConfigurationKey('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY', [
            'configuration_title' => 'Seekmodo: Use Legacy Suggest Dropdown',
            'configuration_value' => 'false',
            'configuration_description' => 'When <b>false</b> (default), the storefront uses the same split-rail <code>&lt;seekmodo-suggest&gt;</code> widget subscribed Seekmodo stores get. When <b>true</b>, falls back to the v1.0.20 flat-row dropdown (<code>seekmodo_typeahead.legacy.js</code>). Leave this <b>false</b> unless you have bespoke CSS that cannot follow the default widget yet. Billing denials keep the modern widget and fill via same-origin Enhanced Native (prefer-local).',
            'configuration_group_id' => $groupId,
            'sort_order' => 1112,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // Sprint 4 PR 6 (v1.0.15+) — recommendations + AI product
        // bundle storefront placements. Default <b>false</b>: the
        // observer doesn't inject any `<div data-seekmodo-placement>`
        // markup, the AJAX endpoint short-circuits, and the JS module
        // finds no placeholders to render. Flip to <b>true</b> to
        // light up the PDP/cart/category recommendation widgets +
        // bundle composer. Per-tenant feature entitlement (Starter+
        // for recommendations, Growth+ for bundles) is enforced on
        // the gateway side — a Hobby tenant flipping this on will
        // see the placeholder divs render but each fetch will 402
        // and leave the slot empty.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED', [
            'configuration_title' => 'Seekmodo: Storefront Recommendations',
            'configuration_value' => 'false',
            'configuration_description' => 'When <b>true</b>, the connector injects recommendation + bundle widget placeholders into the PDP / cart / category templates and renders them via <code>numinix_seekmodo_recommend.php</code>. Each rendered widget bills one <code>searches</code> token against your monthly quota and lands in the <code>searches_recommend</code> display bucket on admin.seekmodo.com -> Usage. Bots are excluded server-side. Requires the gateway to be Sprint 4 or newer and the tenant to be opted-in via admin.seekmodo.com -> Recommendations.',
            'configuration_group_id' => $groupId,
            'sort_order' => 112,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // Sprint 12 (v1.0.8+) — tenant domain lock. Mirrored from
        // tenant.snapshot's `locked_domain` field by
        // RemoteConfig::writeThrough. Empty (default) = no lock; the
        // connector behaves exactly as before. Non-empty = the
        // connector short-circuits every hot-path entry whose
        // current host doesn't match (same code path as MODE=off),
        // letting dev / staging / test storefronts carry the
        // connector without polluting the production tenant's
        // search index, click stream, or auto-promote FSM.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_LOCKED_DOMAIN', [
            'configuration_title' => 'Seekmodo: Locked Storefront Domain',
            'configuration_value' => '',
            'configuration_description' => 'Managed at admin.seekmodo.com. The single canonical production storefront host this tenant is locked to. Empty (default) = no lock; route everything. Non-empty = this connector short-circuits to native Zen Cart search when the current host is neither the lock nor an allowlisted same-apex preview host. Only the locked host indexes and posts analytics/LTR events. Read-only here; set the value on admin.seekmodo.com -> tenant settings -> Storefront domain.',
            'configuration_group_id' => $groupId,
            'sort_order' => 114,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_ALLOWED_STOREFRONT_HOSTS', [
            'configuration_title' => 'Seekmodo: Allowed Preview Hosts',
            'configuration_value' => '',
            'configuration_description' => 'Managed at admin.seekmodo.com. JSON array of same-apex staging/dev hosts allowed read-only Seekmodo search against the production catalog. Empty = none. Mirrored from tenant.snapshot allowed_storefront_hosts.',
            'configuration_group_id' => $groupId,
            'sort_order' => 115,
        ]);

        // v1.0.17 — SKU / part-number exact-match boost. Port of the
        // AKS connector's Sprint 2 EzNumberBooster helper. When the
        // shopper's query matches the SKU-shape regex below, the
        // search payload builder sets `prioritize_exact_match=true`
        // so an exact match on a SKU-bearing field jumps to position
        // 0 regardless of textual relevance scoring. Configurable
        // here; helper lives in includes/functions/numinix_seekmodo_search_lib.php.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_SKU_BOOST_ENABLED', [
            'configuration_title' => 'Seekmodo: SKU Exact-Match Boost',
            'configuration_value' => 'true',
            'configuration_description' => 'When <b>true</b> (default), shopper queries that look like a SKU / part number (alphanumeric + dashes/underscores/dots, 2-32 chars) get <code>prioritize_exact_match=true</code> on the gateway call so the exact-SKU product floats to position 0. Multi-word natural-language queries are unaffected (they still rank by relevance). Generic port of the AKS connector\'s Sprint 2 EzNumberBooster — applies to the full-search path AND the legacy /v1/search-based typeahead fallback. Set to <b>false</b> to disable.',
            'configuration_group_id' => $groupId,
            'sort_order' => 113,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        // v1.0.17 — Trigger regex for the SKU exact-match boost.
        // Default `/^[A-Za-z0-9][A-Za-z0-9_\-\.]{1,31}$/` covers
        // single-token alphanumeric queries 2-32 chars long that
        // start with a letter or digit. A storefront with a non-
        // standard SKU shape (e.g. SKUs that contain spaces or
        // slashes) can override this in init_includes/. A malformed
        // override regex is treated as a no-op — the storefront
        // never 500s on a bad regex.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX', [
            'configuration_title' => 'Seekmodo: SKU Boost Trigger Regex',
            'configuration_value' => '/^[A-Za-z0-9][A-Za-z0-9_\-\.]{1,31}$/',
            'configuration_description' => 'Regex (with delimiters) that the connector matches the trimmed shopper query against to decide whether to apply <b>NUMINIX_SEEKMODO_SKU_BOOST_ENABLED</b>. Default matches single-token alphanumeric queries 2-32 chars long (covers most SKU and part-number shapes). Override this if your catalogue uses non-standard SKUs (e.g. SKUs containing spaces or slashes). A malformed regex is treated as a no-op so a typo here cannot break the storefront.',
            'configuration_group_id' => $groupId,
            'sort_order' => 113,
            'set_function' => null,
        ]);

        // Sprint 4 PR 3 — daily update-check sentinel.  Written by
        // admin/numinix_seekmodo_check_updates.php (cron) and read by
        // the admin shell to render a top-bar "v1.0.X available"
        // one-liner linking to the Updates page.  Empty when the
        // local install is up to date.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_UPDATE_NOTICE', [
            'configuration_title' => 'Seekmodo: Update Notice',
            'configuration_value' => '',
            'configuration_description' => 'Sentinel set by the daily update-check cron when a newer connector release is available. Empty means the local install is current. The Zen Cart admin shell reads this to render a one-line banner linking to the Updates page.',
            'configuration_group_id' => $groupId,
            'sort_order' => 110,
            'set_function' => null,
        ]);

        // v1.0.19 (search-features-plan Sprint 6 PR 1) -- category
        // landing-page redirect. Klevu / Algolia parity: a query that
        // closely matches a single storefront category redirects to
        // that category page instead of rendering a SERP. Default
        // <b>true</b>; mirrored from tenant.snapshot's
        // `category_redirect_enabled` field by RemoteConfig so
        // operators can flip the feature off from admin.seekmodo.com
        // without a redeploy. Resolver lives in
        // includes/functions/numinix_seekmodo_category_redirect_lib.php;
        // observer hook is NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED', [
            'configuration_title' => 'Seekmodo: Category Redirect',
            'configuration_value' => 'true',
            'configuration_description' => 'When <b>true</b> (default), a shopper query that closely matches a single storefront category redirects to that category landing page instead of rendering an advanced_search_result SERP. Same UX pattern as Klevu / Algolia. Matching is conservative: a high similarity floor plus a clear-winner gap so we only redirect when there is genuinely no ambiguity. Set to <b>false</b> to disable; mirrored from admin.seekmodo.com -> tenant settings -> Category Redirect.',
            'configuration_group_id' => $groupId,
            'sort_order' => 115,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'),',
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_MIN_SIMILARITY', [
            'configuration_title' => 'Seekmodo: Category Redirect Similarity Floor',
            'configuration_value' => '0.92',
            'configuration_description' => 'Minimum similarity score (0.80&ndash;1.00) the best category match must clear for the connector to fire a redirect. Default <b>0.92</b> is conservative -- only redirect on near-exact or order-invariant token matches. Lower values surface more redirects but increase the risk of landing the shopper on a related-but-wrong category. Values below 0.80 are clamped to 0.80 at runtime.',
            'configuration_group_id' => $groupId,
            'sort_order' => 116,
            'set_function' => null,
        ]);

        $this->addConfigurationKey('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_CLEAR_WINNER_GAP', [
            'configuration_title' => 'Seekmodo: Category Redirect Clear-Winner Gap',
            'configuration_value' => '0.05',
            'configuration_description' => 'Minimum gap (0.00&ndash;0.20) between the best and second-best category match scores. Prevents the connector from picking arbitrarily between two equally-good candidates -- e.g. a query like "personalised mugs" matching both <b>Personalised Mugs &gt; All Personalised Mugs</b> and <b>Personalised Mugs &gt; Mugs For Her</b>. When the gap is below this floor, the shopper falls through to the regular SERP so they can see both candidates. An exact-score-1.0 winner bypasses this check.',
            'configuration_group_id' => $groupId,
            'sort_order' => 117,
            'set_function' => null,
        ]);

        // M5 — claim-token pairing scratch space. Written by
        // numinix_seekmodo_connect.php at mint-time, read by the
        // storefront callback, and cleared on successful pair.
        $this->addConfigurationKey('NUMINIX_SEEKMODO_INSTALL_TOKEN', [
            'configuration_title' => 'Seekmodo: Install Token',
            'configuration_value' => '',
            'configuration_description' => 'Internal: short-lived install token used by the M5 claim-token pairing flow. Cleared automatically on successful pair. Read-only; do not edit by hand.',
            'configuration_group_id' => $groupId,
            'sort_order' => 200,
        ]);
        $this->addConfigurationKey('NUMINIX_SEEKMODO_INSTALL_TOKEN_EXP', [
            'configuration_title' => 'Seekmodo: Install Token Expires At',
            'configuration_value' => '0',
            'configuration_description' => 'Internal: unix timestamp the install_token above expires at. Read-only.',
            'configuration_group_id' => $groupId,
            'sort_order' => 201,
        ]);

        $this->ensurePluginControlActive();
        $this->deployCatalogRootShims();
        $this->resetLeftoverLegacySuggest();

        return true;
    }

    /**
     * v1.0.8 bridge fix for a Zen Cart core ≥ 2.2.0 bug.
     *
     * `Zencart\PluginSupport\ScriptedInstallerFactory::make()` builds
     * the per-plugin installer with just `($dbConn, $errorContainer)`
     * and never calls `setVersionDetails()`. Core's
     * `doUpgrade()` then calls `updateZenCoreDbFields()`, which reads
     * the typed `$pluginKey` + `$version` properties, and PHP throws
     *
     *     Typed property Zencart\PluginSupport\ScriptedInstaller::
     *     $pluginKey must not be accessed before initialization
     *
     * which the Plugin Manager surfaces as a generic 500 right after
     * the operator clicks "Upgrade".
     *
     * `doInstall()` / `doUninstall()` don't touch the typed
     * properties, so install + uninstall still work — only the
     * upgrade button is affected upstream. We bridge by deriving the
     * three properties from `__DIR__` and forwarding to
     * `setVersionDetails()` before delegating to the parent, so the
     * fix Just Works on any Zen Cart that ever wires the factory
     * correctly in a future patch (the precondition stays satisfied
     * either way).
     *
     * v1.0.13 follow-up — the override has to coexist with **two**
     * upstream ScriptedInstaller signatures:
     *
     *   * Stock Zen Cart 2.0.1 ships `public function doUpgrade()`
     *     (no parameters, no return type) and the same for
     *     `executeUpgrade()`. `setVersionDetails()` and the typed
     *     `$pluginKey/$version/$pluginDir` properties don't exist —
     *     none of the bridge work is needed there.
     *   * Zen Cart 2.0.1+ patched to v2.2.0 (and v2.2.0+ proper)
     *     ships `public function doUpgrade($oldVersion): ?bool`,
     *     plus the typed properties and `setVersionDetails()`.
     *
     * Tenants in the wild are split across both shapes (numinix.com
     * = patched core; redlinestands = stock 2.0.1). A signature that
     * hard-codes one shape fails LSP compatibility on the other and
     * Plugin Manager 500's at class-load time. We keep the override
     * permissive (`$oldVersion = null`, `: ?bool` return is covariant
     * addition over the no-return-type parent) and detect the parent
     * shape with Reflection at call time so the bridge runs only
     * where it's needed.
     *
     * @return bool|null
     */
    public function doUpgrade($oldVersion = null): ?bool
    {
        $this->bridgeVersionDetails($oldVersion);

        // Stock ZC 2.0.1 ScriptedInstaller has no doUpgrade(); calling
        // parent or reflecting it 500s Plugin Manager on tenants like KIP.
        if (!method_exists(parent::class, 'doUpgrade')) {
            return (bool) $this->executeUpgrade($oldVersion);
        }

        // ZC 2.2.0+ parent::doUpgrade() hits updateZenCoreDbFields() which
        // needs typed $pluginKey/$version; skip parent when we only need
        // idempotent config back-fill (same pattern as TM v1.3.6).
        if (method_exists(parent::class, 'setVersionDetails')) {
            return (bool) $this->executeUpgrade($oldVersion);
        }

        $parentMethod = new \ReflectionMethod(parent::class, 'doUpgrade');
        $result = $parentMethod->getNumberOfParameters() > 0
            ? parent::doUpgrade($oldVersion)
            : parent::doUpgrade();

        return $result === null ? null : (bool) $result;
    }

    /**
     * Stock ZC 2.0.1 lacks ScriptedInstallHelpers on the core installer
     * base class; without these shims, Install/Upgrade fatals on
     * $this->addConfigurationKey().
     *
     * @return bool|null
     */
    public function doInstall()
    {
        $this->bridgeVersionDetails(null);
        $installed = $this->executeInstall();

        return $installed === null ? null : (bool) $installed;
    }

    /**
     * Re-running `executeInstall()` is the idempotent upgrade path:
     * every method it calls is either an INSERT IGNORE
     * (`addConfigurationKey` -> `getConfigurationKeyDetails` short-
     * circuit), a NOT-EXISTS-gated registration
     * (`zen_register_admin_pages`), or an UPDATE that's a no-op when
     * already in the target state (`hideGroupFromAdmin`,
     * `renameLegacyGroup`). So upgrading from any prior version to
     * v1.0.9 will:
     *
     *   - back-fill the `admin_pages` rows for the
     *     "Connect to Seekmodo" + "Seekmodo Updates" links if a
     *     previous install used `install_connector.py`'s SQL bootstrap
     *     and bypassed Zen Cart's Plugin Manager flow (in which case
     *     `ensureAdminPage()` never ran),
     *   - add any new configuration rows (e.g.
     *     `NUMINIX_SEEKMODO_LOCKED_DOMAIN` from v1.0.8),
     *   - leave existing rows untouched.
     *
     * v1.0.9 itself adds NO new configuration rows — the new behaviour
     * (zero-touch notifier observer) is purely code in the plugin tree
     * and is picked up the next time application_top.php's auto_loaders
     * stage runs (which on a `numinix-mcp-deploy.path`-driven rsync
     * happens immediately, no admin click required).
     *
     * @param string $oldVersion
     * @return bool
     */
    protected function executeUpgrade($oldVersion = null)
    {
        // Optional `$oldVersion` keeps this signature LSP-compatible
        // with both ZC 2.0.1's `executeUpgrade()` and ZC 2.2.0+'s
        // `executeUpgrade($oldVersion)`. We don't actually consult
        // `$oldVersion` here — the upgrade path is an idempotent
        // re-run of `executeInstall()` (which also resets leftover
        // `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY=true` rows).
        $result = $this->executeInstall();
        return $result === null ? true : (bool) $result;
    }

    /**
     * v1.3.69 — paired / subscribed stores should get the same
     * split-rail `<seekmodo-suggest>` widget Seekmodo ships as the
     * default. Recovery scripts previously wrote
     * NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY=true into `configuration`;
     * that leftover is not a merchant opt-in. Reset it on every
     * Plugin Manager upgrade so the installer default actually
     * lands. Operators who still need the v1.0.20 dropdown can flip
     * the row back after upgrade.
     *
     * Avoid the substring "NULL" in any bind-string (queryFactory
     * string binds rewrite /NULL/ to SQL null). This uses Execute()
     * with a literal SQL string, so it is safe either way.
     */
    private function resetLeftoverLegacySuggest(): void
    {
        $this->dbConn->Execute(
            'UPDATE ' . TABLE_CONFIGURATION
            . " SET configuration_value = 'false'"
            . " WHERE configuration_key = 'NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY'"
            . " AND LOWER(configuration_value) IN ('true', '1', 'yes', 'on')"
        );
    }

    protected function executeUninstall()
    {
        $this->deleteConfigurationKeys([
            'NUMINIX_SEEKMODO_URL',
            'NUMINIX_SEEKMODO_TENANT_ID',
            'NUMINIX_SEEKMODO_SHARED_SECRET',
            'NUMINIX_SEEKMODO_MODE',
            'NUMINIX_SEEKMODO_DEFAULT_MODE',
            'NUMINIX_SEEKMODO_INDEXER_SCHEDULE',
            'NUMINIX_SEEKMODO_TIMEOUT_MS',
            'NUMINIX_SEEKMODO_INDEX_BATCH',
            'NUMINIX_SEEKMODO_DEBUG',
            'NUMINIX_SEEKMODO_AUTO_PROMOTE',
            'NUMINIX_SEEKMODO_AUTO_STATE',
            'NUMINIX_SEEKMODO_AUTO_STATE_SINCE',
            'NUMINIX_SEEKMODO_AUTO_HISTORY',
            'NUMINIX_SEEKMODO_INSTALL_TOKEN',
            'NUMINIX_SEEKMODO_INSTALL_TOKEN_EXP',
            'NUMINIX_BOT_CHECK_BACKEND',
            'NUMINIX_SEEKMODO_UPDATE_NOTICE',
            // Sprint 12 (v1.0.8+).
            'NUMINIX_SEEKMODO_LOCKED_DOMAIN',
            'NUMINIX_SEEKMODO_ALLOWED_STOREFRONT_HOSTS',
            // Sprint 15 (v1.0.13+) — split-bucket timeouts.
            'NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS',
            'NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS',
            // Sprint 3 PR 6 (v1.0.14+) — typeahead path toggle.
            'NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH',
            // v1.3.69 — storefront suggest widget defaults.
            'NUMINIX_SEEKMODO_SUGGEST_ENABLED',
            'NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY',
            // Sprint 4 PR 6 (v1.0.15+) — storefront recommendations + bundles toggle.
            'NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED',
            // v1.0.17 — SKU exact-match boost (AKS Sprint 2 parity).
            'NUMINIX_SEEKMODO_SKU_BOOST_ENABLED',
            'NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX',
            // v1.0.19 -- category landing-page redirect.
            'NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED',
            'NUMINIX_SEEKMODO_CATEGORY_REDIRECT_MIN_SIMILARITY',
            'NUMINIX_SEEKMODO_CATEGORY_REDIRECT_CLEAR_WINNER_GAP',
        ]);
        zen_deregister_admin_pages('numinixSeekmodoConnect');
        zen_deregister_admin_pages('numinixSeekmodoUpdates');
    }

    /**
     * Copy flat catalog-root shims next to index.php.
     *
     * Zen Cart Plugin Manager overlays zc_plugins/.../catalog/ into the
     * live catalog on successful Install/Upgrade, but subdirectory /shop
     * installs and some Zen Cart 1.5.7 German builds leave the eight
     * numinix_seekmodo_*.php entrypoints missing (pair_callback then
     * returns storefront HTML). Explicitly copy them again so Connect
     * works after Upgrade without a separate manual FTP step.
     */
    private function deployCatalogRootShims(): void
    {
        if (!defined('DIR_FS_CATALOG') || !is_string(DIR_FS_CATALOG) || DIR_FS_CATALOG === '') {
            return;
        }

        $srcDir = __DIR__ . '/../catalog/';
        $destDir = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/';
        if (!is_dir($srcDir) || !is_dir($destDir)) {
            return;
        }

        $shims = [
            'numinix_seekmodo_pair_callback.php',
            'numinix_seekmodo_suggest.php',
            'numinix_seekmodo_push_catalog.php',
            'numinix_seekmodo_click.php',
            'numinix_seekmodo_recommend.php',
            'numinix_seekmodo_index_delta.php',
            'numinix_seekmodo_forget_me.php',
            'numinix_seekmodo_reconcile_cron.php',
        ];

        foreach ($shims as $file) {
            $src = $srcDir . $file;
            $dest = $destDir . $file;
            if (!is_file($src)) {
                continue;
            }
            @copy($src, $dest);
        }
    }

    /**
     * Register the admin "Connect to Seekmodo" page so it shows up in
     * the Tools menu. ZC 1.5.7 uses zen_register_admin_page()
     * (singular); 1.5.8+ plugin manager adds zen_register_admin_pages()
     * (plural). Shared helper in numinix_seekmodo_admin_pages.php
     * handles both; fall back to direct INSERT when neither API exists.
     */
    private function ensureAdminPage(): void
    {
        $helper = __DIR__ . '/../admin/includes/functions/extra_functions/numinix_seekmodo_admin_pages.php';
        if (is_file($helper)) {
            require_once $helper;
            if (function_exists('numinix_seekmodo_ensure_admin_pages')) {
                numinix_seekmodo_ensure_admin_pages();
                return;
            }
        }

        if (function_exists('zen_register_admin_pages')) {
            zen_register_admin_pages(
                'numinixSeekmodoConnect',
                'BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT',
                'FILENAME_NUMINIX_SEEKMODO_CONNECT',
                '',
                'tools',
                'Y',
                500
            );
            zen_register_admin_pages(
                'numinixSeekmodoUpdates',
                'BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES',
                'FILENAME_NUMINIX_SEEKMODO_UPDATES',
                '',
                'tools',
                'Y',
                510
            );
            return;
        }

        if (!function_exists('zen_register_admin_page')) {
            return;
        }

        if (!function_exists('zen_page_key_exists') || !zen_page_key_exists('numinixSeekmodoConnect')) {
            zen_register_admin_page(
                'numinixSeekmodoConnect',
                'BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT',
                'FILENAME_NUMINIX_SEEKMODO_CONNECT',
                '',
                'tools',
                'Y',
                500
            );
        }
        if (!function_exists('zen_page_key_exists') || !zen_page_key_exists('numinixSeekmodoUpdates')) {
            zen_register_admin_page(
                'numinixSeekmodoUpdates',
                'BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES',
                'FILENAME_NUMINIX_SEEKMODO_UPDATES',
                '',
                'tools',
                'Y',
                510
            );
        }
    }

    /**
     * Flip CONFIGURATION_GROUP_VISIBLE_<gid> to 'false' so the
     * "Seekmodo Search" group disappears from Admin -> Configuration.
     * The rows themselves stay (they're a runtime cache for the
     * gateway snapshot); they're just no longer human-edited from
     * Zen Cart admin. Idempotent.
     */
    private function hideGroupFromAdmin(int $groupId): void
    {
        global $db;
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION
            . " SET configuration_value = 'false',"
            . " configuration_description = 'Hidden by default — settings are managed on admin.seekmodo.com.'"
            . " WHERE configuration_key = 'CONFIGURATION_GROUP_VISIBLE_{$groupId}'"
            . " AND configuration_value <> 'false'"
        );
    }

    /**
     * One-time rename of the legacy "Numinix Seekmodo" configuration_group
     * to "Seekmodo Search". A no-op on fresh installs and on installs
     * where the rename has already happened.
     */
    private function renameLegacyGroup(): void
    {
        global $db;
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION_GROUP
            . " SET configuration_group_title = '" . zen_db_input(self::GROUP_TITLE) . "'"
            . " WHERE configuration_group_title = '" . zen_db_input(self::GROUP_TITLE_LEGACY) . "'"
        );
    }

    /**
     * Find or create the dedicated configuration_group row so all the
     * NUMINIX_SEEKMODO_* keys live under a single Admin -> Configuration
     * subsection labeled "Seekmodo Search" rather than getting scattered
     * into the generic Sessions or My Store groups.
     */
    private function ensureGroup(): int
    {
        global $db;
        $existing = $db->Execute(
            "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP
            . " WHERE configuration_group_title = '" . zen_db_input(self::GROUP_TITLE) . "' LIMIT 1"
        );
        if (!$existing->EOF) {
            return (int)$existing->fields['configuration_group_id'];
        }
        $sortOrderRow = $db->Execute(
            "SELECT MAX(sort_order) AS max_sort FROM " . TABLE_CONFIGURATION_GROUP
        );
        $newSort = (int)($sortOrderRow->fields['max_sort'] ?? 0) + 1;
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION_GROUP . " (configuration_group_title, configuration_group_description, sort_order, visible)"
            . " VALUES ('" . zen_db_input(self::GROUP_TITLE) . "',"
            . " 'Runtime cache for the Seekmodo Search connector. Settings are managed on admin.seekmodo.com; this group is hidden from the Zen Cart admin and only exposed for diagnostic inspection.',"
            . " {$newSort}, 1)"
        );
        $newId = (int)$db->Insert_ID();
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)"
            . " VALUES ('Display configuration group {$newId}?', 'CONFIGURATION_GROUP_VISIBLE_{$newId}', 'false',"
            . " 'Hidden by default — settings are managed on admin.seekmodo.com.', 6, 1,"
            . " 'zen_cfg_select_option(array(\\'true\\', \\'false\\'),', NOW())"
        );
        return $newId;
    }

    private function bridgeVersionDetails($oldVersion): void
    {
        if (
            !method_exists(parent::class, 'setVersionDetails')
            || (isset($this->pluginKey) && isset($this->version) && isset($this->pluginDir))
        ) {
            return;
        }

        $pluginDir = dirname(__DIR__);
        $this->setVersionDetails([
            'pluginKey' => basename(dirname($pluginDir)),
            'pluginDir' => $pluginDir,
            'version' => basename($pluginDir),
            'oldVersion' => (string) ($oldVersion ?? ''),
        ]);
    }

    /**
     * Mark this plugin folder as the active installed version in
     * plugin_control so catalog auto_loaders load the right tree.
     */
    private function ensurePluginControlActive(): void
    {
        if (!defined('TABLE_PLUGIN_CONTROL')) {
            return;
        }

        $version = basename(dirname(__DIR__));
        $this->dbConn->Execute(
            'UPDATE ' . TABLE_PLUGIN_CONTROL
            . " SET version = '" . $this->dbConn->prepare_input($version) . "', status = 1, infs = 1"
            . " WHERE unique_key = 'Seekmodo'"
        );

        if (defined('TABLE_PLUGIN_CONTROL_VERSIONS')) {
            $this->dbConn->Execute(
                'INSERT INTO ' . TABLE_PLUGIN_CONTROL_VERSIONS
                . " (unique_key, author, version, zc_versions, infs)"
                . " VALUES ('Seekmodo', 'Numinix', '" . $this->dbConn->prepare_input($version) . "', '[\"v157\", \"v158\", \"v200\"]', 1)"
                . ' ON DUPLICATE KEY UPDATE infs = 1'
            );
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    protected function addConfigurationKey(string $key_name, array $properties): int
    {
        if ($this->getConfigurationKeyDetails($key_name, true) !== false) {
            return 0;
        }

        $fields = [
            'configuration_title',
            'configuration_value',
            'configuration_description',
            'configuration_group_id',
            'sort_order',
            'use_function',
            'set_function',
            'val_function',
        ];

        $sql_data_array = [
            ['fieldName' => 'configuration_key', 'value' => $key_name, 'type' => 'string'],
        ];
        foreach ($fields as $field) {
            if (!isset($properties[$field])) {
                continue;
            }
            $type = in_array($field, ['configuration_group_id', 'sort_order'], true) ? 'integer' : 'string';
            $sql_data_array[] = ['fieldName' => $field, 'value' => $properties[$field], 'type' => $type];
        }
        $sql_data_array[] = ['fieldName' => 'date_added', 'value' => 'NOW()', 'type' => 'passthru'];

        $this->executeInstallerDbPerform(TABLE_CONFIGURATION, $sql_data_array);

        return (int) $this->dbConn->insert_ID();
    }

    /**
     * @param list<string> $key_names
     */
    protected function deleteConfigurationKeys(array $key_names): int
    {
        if ($key_names === []) {
            return 0;
        }

        $keys_list = implode(
            "','",
            array_map(fn($val) => $this->dbConn->prepare_input($val), $key_names)
        );
        $this->executeInstallerSelectQuery(
            'DELETE FROM ' . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . $keys_list . "')"
        );

        return (int) $this->dbConn->affectedRows();
    }

    /**
     * @return array<string, mixed>|bool
     */
    protected function getConfigurationKeyDetails(string $key_name, bool $only_check_existence = false)
    {
        $sql = 'SELECT * FROM ' . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $this->dbConn->prepare_input($key_name) . "'";
        $result = $this->executeInstallerSelectQuery($sql, 1);
        if ($result === false || $result->EOF) {
            return $only_check_existence ? false : false;
        }

        return $only_check_existence ? true : $result->fields;
    }

    /**
     * @param array<int, array<string, mixed>> $sql_data_array
     */
    protected function executeInstallerDbPerform(
        string $table,
        array $sql_data_array,
        string $performType = 'INSERT',
        string $whereCondition = '',
        bool $debug = false
    ): bool {
        $this->dbConn->dieOnErrors = false;
        $this->dbConn->perform($table, $sql_data_array, $performType, $whereCondition, $debug);
        if ($this->dbConn->error_number !== 0) {
            $this->errorContainer->addError(0, $this->dbConn->error_text, true, PLUGIN_INSTALL_SQL_FAILURE);
            return false;
        }
        $this->dbConn->dieOnErrors = true;

        return true;
    }

    /**
     * @return bool|\queryFactoryResult
     */
    protected function executeInstallerSelectQuery(string $sql, ?int $limit = null)
    {
        $this->dbConn->dieOnErrors = false;
        $result = $this->dbConn->Execute($sql, $limit);
        if ($this->dbConn->error_number !== 0) {
            $this->errorContainer->addError(0, $this->dbConn->error_text, true, PLUGIN_INSTALL_SQL_FAILURE);
            return false;
        }
        $this->dbConn->dieOnErrors = true;

        return $result;
    }
}

<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Durable chatbot transcript archive for admin browsing.
 *
 * Session JSON files remain the live context for the model (and may expire).
 * This table keeps a searchable copy for Tools → Support Tickets.
 */
final class ChatArchive
{
    public static function inboundTableName(): string
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        return $prefix . 'seekmodo_ai_chatbot_inbound_uid';
    }

    public static function tableName(): string
    {
        if (defined('TABLE_SEEKMODO_AI_CHATBOT_CHATS')) {
            return TABLE_SEEKMODO_AI_CHATBOT_CHATS;
        }
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        return $prefix . 'seekmodo_ai_chatbot_chats';
    }

    /** @var bool Skip repeated SHOW TABLES / ALTER probes within one request. */
    private static bool $tableReady = false;

    public static function ensureTable(): bool
    {
        global $db;
        if (self::$tableReady) {
            return true;
        }
        if (!isset($db) || !is_object($db)) {
            return false;
        }
        $table = self::tableName();
        $check = $db->Execute("SHOW TABLES LIKE '" . $db->prepareInput($table) . "'");
        if (!$check || $check->EOF) {
            $db->Execute(
                'CREATE TABLE IF NOT EXISTS `' . str_replace('`', '``', $table) . '` (
                    `chat_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_id` VARCHAR(64) NOT NULL,
                    `customers_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `customer_email` VARCHAR(96) NOT NULL DEFAULT \'\',
                    `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
                    `customer_name` VARCHAR(128) NOT NULL DEFAULT \'\',
                    `is_guest` TINYINT(1) NOT NULL DEFAULT 1,
                    `preview` VARCHAR(255) NOT NULL DEFAULT \'\',
                    `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `tickets_sent` INT UNSIGNED NOT NULL DEFAULT 0,
                    `messages_json` MEDIUMTEXT NOT NULL,
                    `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
                    `resolved_at` DATETIME NULL DEFAULT NULL,
                    `resolved_by_admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `handed_off_at` DATETIME NULL DEFAULT NULL,
                    `last_admin_reply_at` DATETIME NULL DEFAULT NULL,
                    `last_outbound_message_id` VARCHAR(255) NOT NULL DEFAULT \'\',
                    `date_created` DATETIME NOT NULL,
                    `date_updated` DATETIME NOT NULL,
                    PRIMARY KEY (`chat_id`),
                    UNIQUE KEY `idx_seekmodo_ai_chat_session` (`session_id`),
                    KEY `idx_seekmodo_ai_chat_email` (`customer_email`),
                    KEY `idx_seekmodo_ai_chat_customers` (`customers_id`),
                    KEY `idx_seekmodo_ai_chat_status` (`status`),
                    KEY `idx_seekmodo_ai_chat_updated` (`date_updated`),
                    KEY `idx_seekmodo_ai_chat_created` (`date_created`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        self::ensureStatusColumns();
        self::ensureEmailVerifiedColumn();
        self::ensureOutboundMessageIdColumn();
        self::ensureInboundDedupeTable();
        self::$tableReady = true;
        return true;
    }

    private static function ensureOutboundMessageIdColumn(): void
    {
        global $db;
        $table = self::tableName();
        $safe = str_replace('`', '``', $table);
        $col = $db->Execute(
            "SHOW COLUMNS FROM `" . $safe . "` LIKE 'last_outbound_message_id'"
        );
        if ($col && !$col->EOF) {
            return;
        }
        $db->Execute(
            'ALTER TABLE `' . $safe . '` ADD COLUMN `last_outbound_message_id` VARCHAR(255) NOT NULL DEFAULT \'\' AFTER `last_admin_reply_at`'
        );
    }

    private static function ensureInboundDedupeTable(): void
    {
        global $db;
        $table = self::inboundTableName();
        $check = $db->Execute("SHOW TABLES LIKE '" . $db->prepareInput($table) . "'");
        if ($check && !$check->EOF) {
            return;
        }
        $db->Execute(
            'CREATE TABLE IF NOT EXISTS `' . str_replace('`', '``', $table) . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `folder` VARCHAR(128) NOT NULL DEFAULT \'INBOX\',
                `imap_uid` INT UNSIGNED NOT NULL DEFAULT 0,
                `rfc_message_id` VARCHAR(255) NOT NULL DEFAULT \'\',
                `chat_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_processed` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_ogc_inbound_folder_uid` (`folder`, `imap_uid`),
                KEY `idx_ogc_inbound_msgid` (`rfc_message_id`),
                KEY `idx_ogc_inbound_chat` (`chat_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Add handoff/resolve columns on upgraded installs. */
    private static function ensureStatusColumns(): void
    {
        global $db;
        $table = self::tableName();
        $needed = [
            'status' => "ADD COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'open' AFTER `messages_json`",
            'resolved_at' => 'ADD COLUMN `resolved_at` DATETIME NULL DEFAULT NULL AFTER `status`',
            'resolved_by_admin_id' => 'ADD COLUMN `resolved_by_admin_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `resolved_at`',
            'handed_off_at' => 'ADD COLUMN `handed_off_at` DATETIME NULL DEFAULT NULL AFTER `resolved_by_admin_id`',
            'last_admin_reply_at' => 'ADD COLUMN `last_admin_reply_at` DATETIME NULL DEFAULT NULL AFTER `handed_off_at`',
            'assigned_admin_id' => 'ADD COLUMN `assigned_admin_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_admin_reply_at`',
            'claimed_at' => 'ADD COLUMN `claimed_at` DATETIME NULL DEFAULT NULL AFTER `assigned_admin_id`',
            'last_shopper_message_at' => 'ADD COLUMN `last_shopper_message_at` DATETIME NULL DEFAULT NULL AFTER `claimed_at`',
            'shopper_presence_at' => 'ADD COLUMN `shopper_presence_at` DATETIME NULL DEFAULT NULL AFTER `last_shopper_message_at`',
            'archived_at' => 'ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL AFTER `shopper_presence_at`',
            'live_requested_at' => 'ADD COLUMN `live_requested_at` DATETIME NULL DEFAULT NULL AFTER `archived_at`',
            'assigned_seekmodo_user_id' => 'ADD COLUMN `assigned_seekmodo_user_id` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `live_requested_at`',
            'assigned_seekmodo_name' => 'ADD COLUMN `assigned_seekmodo_name` VARCHAR(128) NOT NULL DEFAULT \'\' AFTER `assigned_seekmodo_user_id`',
            'assigned_seekmodo_email' => 'ADD COLUMN `assigned_seekmodo_email` VARCHAR(175) NOT NULL DEFAULT \'\' AFTER `assigned_seekmodo_name`',
        ];
        foreach ($needed as $column => $alterSql) {
            $col = $db->Execute(
                "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE '" . $db->prepareInput($column) . "'"
            );
            if ($col && !$col->EOF) {
                continue;
            }
            $db->Execute('ALTER TABLE `' . str_replace('`', '``', $table) . '` ' . $alterSql);
        }
        $idx = $db->Execute(
            "SHOW INDEX FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = 'idx_seekmodo_ai_chat_status'"
        );
        if (!$idx || $idx->EOF) {
            $db->Execute(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD KEY `idx_seekmodo_ai_chat_status` (`status`)'
            );
        }
        $idxArchived = $db->Execute(
            "SHOW INDEX FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = 'idx_seekmodo_ai_chat_archived'"
        );
        if (!$idxArchived || $idxArchived->EOF) {
            $db->Execute(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD KEY `idx_seekmodo_ai_chat_archived` (`archived_at`)'
            );
        }
    }

    /**
     * Track whether customer_email was OTP-verified (guests) or is a trusted account email.
     * Existing rows with a stored email are backfilled as verified (historically only set after OTP/login).
     */
    private static function ensureEmailVerifiedColumn(): void
    {
        global $db;
        $table = self::tableName();
        $safe = str_replace('`', '``', $table);
        $col = $db->Execute(
            "SHOW COLUMNS FROM `" . $safe . "` LIKE 'email_verified'"
        );
        if ($col && !$col->EOF) {
            return;
        }
        $db->Execute(
            'ALTER TABLE `' . $safe . '` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_email`'
        );
        $db->Execute(
            'UPDATE `' . $safe . "` SET email_verified = 1
              WHERE TRIM(customer_email) <> ''"
        );
    }

    /**
     * Minutes of inactivity after which a shopper is treated as offline (email preferred).
     */
    public static function shopperPresenceIdleMinutes(): int
    {
        $minutes = defined('SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES')
            ? (int) SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES
            : 30;
        return max(5, min(1440, $minutes));
    }

    /**
     * True when the shopper is likely still in the storefront widget (or chat is already live).
     *
     * @param array<string, mixed>|null $chat
     */
    public static function isShopperPresent(?array $chat): bool
    {
        if ($chat === null) {
            return false;
        }
        $status = self::normalizeStatus((string) ($chat['status'] ?? 'open'));
        if ($status === 'live') {
            return true;
        }
        if ($status === 'resolved') {
            return false;
        }

        $idleSeconds = self::shopperPresenceIdleMinutes() * 60;
        $now = time();
        foreach (['shopper_presence_at', 'last_shopper_message_at'] as $field) {
            $raw = trim((string) ($chat[$field] ?? ''));
            if ($raw === '' || $raw === '0000-00-00 00:00:00') {
                continue;
            }
            $ts = strtotime($raw);
            if ($ts !== false && ($now - $ts) <= $idleSeconds) {
                return true;
            }
        }

        $sessionId = trim((string) ($chat['session_id'] ?? ''));
        if ($sessionId !== '' && self::hasFreshSessionFile($sessionId, $idleSeconds)) {
            return true;
        }

        return false;
    }

    public static function hasFreshSessionFile(string $sessionId, int $maxAgeSeconds): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($safe === '') {
            return false;
        }
        try {
            $file = \SeekmodoAiChatbot\ChatAgent::sessionsDirectory() . '/' . $safe . '.json';
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_file($file)) {
            return false;
        }
        $mtime = @filemtime($file);
        if ($mtime === false) {
            return false;
        }
        return (time() - $mtime) <= max(60, $maxAgeSeconds);
    }

    /** Stamp that the shopper's widget is currently open / polling. */
    public static function touchShopperPresence(int $chatId): void
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . '
                SET shopper_presence_at = NOW()
              WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
    }

    /**
     * Reopen an email-handed-off chat when the shopper returns to the widget.
     * Keeps the full transcript (live + email) intact.
     */
    public static function reopenFromEmailHandoff(int $chatId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }
        if (self::normalizeStatus((string) ($row['status'] ?? '')) !== 'handed_off') {
            return true;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . "
                SET status = 'open',
                    shopper_presence_at = NOW(),
                    date_updated = NOW()
              WHERE chat_id = " . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /**
     * Admin email replies require a verified contact email (OTP for guests, account email for logged-in).
     *
     * @param array<string, mixed>|null $chat
     */
    public static function canAdminReply(?array $chat): bool
    {
        if ($chat === null) {
            return false;
        }
        $email = strtolower(trim((string) ($chat['customer_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ((int) ($chat['email_verified'] ?? 0) === 1) {
            return true;
        }
        // Logged-in account emails are trusted even on older rows missing the flag.
        return (int) ($chat['customers_id'] ?? 0) > 0;
    }

    /**
     * Upsert a chat transcript after each assistant turn.
     *
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @param array{logged_in: bool, customers_id: int, email: string, first_name: string, last_name?: string} $shopper
     * @param array{created: int, updated: int, tickets_sent: int} $meta
     */
    public static function upsert(
        string $sessionId,
        array $messages,
        array $shopper,
        array $meta
    ): void {
        global $db;
        if (!isset($db) || !is_object($db)) {
            return;
        }
        if (!self::ensureTable()) {
            return;
        }

        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($sessionId === '') {
            return;
        }

        $display = self::displayMessages($messages);
        if ($display === []) {
            return;
        }
        // Stamp seq on fresh display so inserts and first-write merges stay ordered.
        $display = self::ensureMessageSequences($display);

        $existingMessages = [];
        $existingStatus = 'open';
        $createdTs = (int) ($meta['created'] ?? time());
        $updatedTs = (int) ($meta['updated'] ?? time());
        if ($createdTs <= 0) {
            $createdTs = time();
        }
        if ($updatedTs <= 0) {
            $updatedTs = time();
        }

        $customersId = (int) ($shopper['customers_id'] ?? 0);
        $email = strtolower(trim((string) ($shopper['email'] ?? '')));
        $name = self::formatShopperName($shopper);
        // Name the shopper (or the model via set_shopper_name) supplied in-chat.
        $providedName = trim((string) ($meta['provided_name'] ?? ''));
        if ($providedName !== '') {
            $name = $providedName;
        }
        $isGuest = empty($shopper['logged_in']) ? 1 : 0;
        $emailVerified = (!empty($shopper['logged_in']) && $email !== '') ? 1 : 0;
        if ($customersId > 0 && ($email === '' || $name === '')) {
            $lookedUp = self::lookupCustomer($customersId);
            if ($lookedUp !== null) {
                if ($email === '') {
                    $email = $lookedUp['email'];
                }
                if ($name === '') {
                    $name = $lookedUp['name'];
                }
            }
        }
        if ($customersId > 0 && $email !== '') {
            $emailVerified = 1;
        }
        if ($customersId < 1 && $email !== '') {
            $customersId = self::lookupCustomersIdByEmail($email);
            if ($customersId > 0) {
                $isGuest = 0;
                $emailVerified = 1;
            }
        }

        $table = self::tableName();
        $existing = $db->Execute(
            'SELECT chat_id, customer_email, customer_name, customers_id, is_guest, email_verified, date_created, messages_json, status
               FROM ' . $table . "
              WHERE session_id = '" . $db->prepareInput($sessionId) . "'
              LIMIT 1"
        );

        if ($existing && !$existing->EOF) {
            $decodedExisting = json_decode((string) ($existing->fields['messages_json'] ?? '[]'), true);
            if (is_array($decodedExisting)) {
                $existingMessages = $decodedExisting;
            }
            $existingStatus = self::normalizeStatus((string) ($existing->fields['status'] ?? 'open'));
            $display = self::mergeArchiveMessages($display, $existingMessages);
            if ($display === []) {
                return;
            }
            // Prefer known identity; don't wipe an email if a later guest turn has none.
            $keepEmail = trim((string) $existing->fields['customer_email']);
            $keepName = trim((string) $existing->fields['customer_name']);
            $keepCid = (int) $existing->fields['customers_id'];
            $keepGuest = (int) $existing->fields['is_guest'];
            $keepVerified = (int) ($existing->fields['email_verified'] ?? 0);
            if ($email === '' && $keepEmail !== '') {
                $email = $keepEmail;
                if ($emailVerified === 0) {
                    $emailVerified = $keepVerified;
                }
            }
            if ($name === '' && $keepName !== '') {
                $name = $keepName;
            }
            if ($customersId < 1 && $keepCid > 0) {
                $customersId = $keepCid;
            }
            if ($isGuest === 1 && ($keepGuest === 0 || $customersId > 0)) {
                $isGuest = 0;
            }
            if ($keepVerified === 1) {
                $emailVerified = 1;
            }
            $dateCreated = (string) $existing->fields['date_created'];
            if ($dateCreated === '' || $dateCreated === '0000-00-00 00:00:00') {
                $dateCreated = date('Y-m-d H:i:s', $createdTs);
            }

            $preview = self::previewFromMessages($display);
            $tickets = (int) ($meta['tickets_sent'] ?? 0);
            $json = json_encode($display, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || $json === '') {
                $json = '[]';
            }

            $db->Execute(
                'UPDATE ' . $table . " SET
                    customers_id = " . (int) $customersId . ",
                    customer_email = '" . $db->prepareInput($email) . "',
                    email_verified = " . (int) $emailVerified . ",
                    customer_name = '" . $db->prepareInput(self::clip($name, 128)) . "',
                    is_guest = " . (int) $isGuest . ",
                    preview = '" . $db->prepareInput(self::clip($preview, 255)) . "',
                    message_count = " . count($display) . ",
                    tickets_sent = " . (int) $tickets . ",
                    messages_json = '" . $db->prepareInput($json) . "',
                    status = '" . $db->prepareInput($existingStatus) . "',
                    date_created = '" . $db->prepareInput($dateCreated) . "',
                    date_updated = '" . $db->prepareInput(date('Y-m-d H:i:s', $updatedTs)) . "'
                  WHERE chat_id = " . (int) $existing->fields['chat_id']
            );
            return;
        }

        $preview = self::previewFromMessages($display);
        $tickets = (int) ($meta['tickets_sent'] ?? 0);
        $json = json_encode($display, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        $db->Execute(
            'INSERT INTO ' . $table . " (
                session_id, customers_id, customer_email, email_verified, customer_name, is_guest,
                preview, message_count, tickets_sent, messages_json, status,
                date_created, date_updated
             ) VALUES (
                '" . $db->prepareInput($sessionId) . "',
                " . (int) $customersId . ",
                '" . $db->prepareInput($email) . "',
                " . (int) $emailVerified . ",
                '" . $db->prepareInput(self::clip($name, 128)) . "',
                " . (int) $isGuest . ",
                '" . $db->prepareInput(self::clip($preview, 255)) . "',
                " . count($display) . ",
                " . (int) $tickets . ",
                '" . $db->prepareInput($json) . "',
                'open',
                '" . $db->prepareInput(date('Y-m-d H:i:s', $createdTs)) . "',
                '" . $db->prepareInput(date('Y-m-d H:i:s', $updatedTs)) . "'
             )"
        );
    }

    /**
     * Attach / refresh contact identity for future admin reply emails.
     * Looks up customers_id from email when possible.
     */
    public static function attachContact(
        string $sessionId,
        string $email,
        string $name = '',
        int $customersId = 0
    ): void {
        global $db;
        if (!isset($db) || !is_object($db) || !self::ensureTable()) {
            return;
        }
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($sessionId === '' || ($email === '' && $customersId < 1 && $name === '')) {
            return;
        }

        if ($customersId < 1 && $email !== '') {
            $customersId = self::lookupCustomersIdByEmail($email);
        }
        if (($email === '' || $name === '') && $customersId > 0) {
            $lookedUp = self::lookupCustomer($customersId);
            if ($lookedUp !== null) {
                if ($email === '') {
                    $email = $lookedUp['email'];
                }
                if ($name === '') {
                    $name = $lookedUp['name'];
                }
            }
        }

        $isGuest = $customersId > 0 ? 0 : 1;
        $table = self::tableName();
        $existing = $db->Execute(
            'SELECT chat_id, customer_email, customer_name, customers_id, email_verified
               FROM ' . $table . "
              WHERE session_id = '" . $db->prepareInput($sessionId) . "'
              LIMIT 1"
        );
        if (!$existing || $existing->EOF) {
            return;
        }

        $keepEmail = trim((string) $existing->fields['customer_email']);
        $keepName = trim((string) $existing->fields['customer_name']);
        $keepCid = (int) $existing->fields['customers_id'];
        $keepVerified = (int) ($existing->fields['email_verified'] ?? 0);
        if ($email === '') {
            $email = $keepEmail;
        }
        if ($name === '') {
            $name = $keepName;
        }
        if ($customersId < 1) {
            $customersId = $keepCid;
        }
        if ($customersId > 0) {
            $isGuest = 0;
        }
        // attachContact is only used after OTP success or for trusted account emails.
        $emailVerified = ($email !== '') ? 1 : $keepVerified;

        $db->Execute(
            'UPDATE ' . $table . " SET
                customers_id = " . (int) $customersId . ",
                customer_email = '" . $db->prepareInput($email) . "',
                email_verified = " . (int) $emailVerified . ",
                customer_name = '" . $db->prepareInput(self::clip($name, 128)) . "',
                is_guest = " . (int) $isGuest . ",
                date_updated = NOW()
              WHERE chat_id = " . (int) $existing->fields['chat_id']
        );
    }

    /**
     * @param array{first_name?: string, last_name?: string} $shopper
     */
    private static function formatShopperName(array $shopper): string
    {
        return trim(
            trim((string) ($shopper['first_name'] ?? '')) . ' ' . trim((string) ($shopper['last_name'] ?? ''))
        );
    }

    /** @return array{email: string, name: string}|null */
    private static function lookupCustomer(int $customersId): ?array
    {
        global $db;
        if ($customersId < 1 || !defined('TABLE_CUSTOMERS')) {
            return null;
        }
        $row = $db->Execute(
            'SELECT customers_email_address, customers_firstname, customers_lastname
               FROM ' . TABLE_CUSTOMERS . '
              WHERE customers_id = ' . (int) $customersId . '
              LIMIT 1'
        );
        if (!$row || $row->EOF) {
            return null;
        }
        $name = trim(
            trim((string) $row->fields['customers_firstname']) . ' ' . trim((string) ($row->fields['customers_lastname'] ?? ''))
        );
        return [
            'email' => strtolower(trim((string) $row->fields['customers_email_address'])),
            'name' => $name,
        ];
    }

    private static function lookupCustomersIdByEmail(string $email): int
    {
        global $db;
        $email = strtolower(trim($email));
        if ($email === '' || !defined('TABLE_CUSTOMERS')) {
            return 0;
        }
        $row = $db->Execute(
            'SELECT customers_id FROM ' . TABLE_CUSTOMERS . "
              WHERE LOWER(customers_email_address) = '" . $db->prepareInput($email) . "'
              LIMIT 1"
        );
        if (!$row || $row->EOF) {
            return 0;
        }
        return (int) $row->fields['customers_id'];
    }

    private static function clip(string $value, int $maxLen): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLen);
        }
        return substr($value, 0, $maxLen);
    }

    /**
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @return list<array<string, mixed>>
     */
    public static function displayMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant' && $role !== 'admin') {
                continue;
            }
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $entry = ['role' => $role, 'content' => $content];
            if (!empty($m['seq'])) {
                $entry['seq'] = (int) $m['seq'];
            }
            if (!empty($m['date'])) {
                $entry['date'] = (string) $m['date'];
            }
            if ($role === 'admin') {
                if (!empty($m['admin_id'])) {
                    $entry['admin_id'] = (int) $m['admin_id'];
                }
                if (!empty($m['admin_name'])) {
                    $entry['admin_name'] = (string) $m['admin_name'];
                }
                if (!empty($m['channel'])) {
                    $entry['channel'] = (string) $m['channel'];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Assign durable monotonic seq to every message (backfill legacy rows).
     * Also repairs archives corrupted by the old index-vs-timestamp merge
     * (admin/live replies sunk to the end) by re-interleaving dated staff
     * messages into an interpolated user/assistant timeline.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function ensureMessageSequences(array $messages, ?string $dateCreated = null, ?string $dateUpdated = null): array
    {
        return self::repairMessageOrder($messages, $dateCreated, $dateUpdated);
    }

    /**
     * Rebuild chronological order for display/storage.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function repairMessageOrder(array $messages, ?string $dateCreated = null, ?string $dateUpdated = null): array
    {
        $staff = [];
        $spine = [];
        $adminBodies = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'admin' || $role === 'agent_note') {
                $staff[] = $m;
                if ($role === 'admin') {
                    $adminBodies[$content] = true;
                }
                continue;
            }
            $spine[] = $m;
        }

        // Drop session-echo duplicates of archive admin rows.
        if ($adminBodies !== []) {
            $filtered = [];
            foreach ($spine as $m) {
                $role = (string) ($m['role'] ?? '');
                $content = trim((string) ($m['content'] ?? ''));
                if ($role === 'assistant' && preg_match('/^\[Human agent[^\]]*\]:\s*(.+)$/s', $content, $hm)) {
                    $body = trim((string) ($hm[1] ?? ''));
                    if ($body !== '' && isset($adminBodies[$body])) {
                        continue;
                    }
                }
                $filtered[] = $m;
            }
            $spine = $filtered;
        }

        if ($staff === []) {
            return self::assignSequentialSeq($spine);
        }

        $createdTs = $dateCreated ? strtotime($dateCreated) : false;
        $updatedTs = $dateUpdated ? strtotime($dateUpdated) : false;
        if ($createdTs === false) {
            $createdTs = time() - 3600;
        }
        if ($updatedTs === false || $updatedTs < $createdTs) {
            $updatedTs = $createdTs + max(120, count($spine) * 45);
        }

        $timed = [];
        $n = count($spine);
        foreach ($spine as $i => $m) {
            $t = null;
            if (!empty($m['date'])) {
                $parsed = strtotime((string) $m['date']);
                if ($parsed !== false) {
                    $t = $parsed;
                }
            }
            if ($t === null) {
                $t = (int) round(
                    $createdTs + ($n <= 1 ? 0 : (($updatedTs - $createdTs) * ($i / max($n - 1, 1))))
                );
            }
            $timed[] = ['t' => $t, 'ord' => $i, 'staff' => 0, 'm' => $m];
        }
        foreach ($staff as $si => $m) {
            $t = null;
            if (!empty($m['date'])) {
                $parsed = strtotime((string) $m['date']);
                if ($parsed !== false) {
                    $t = $parsed;
                }
            }
            if ($t === null) {
                $t = $updatedTs;
            }
            $timed[] = ['t' => $t, 'ord' => 100000 + $si, 'staff' => 1, 'm' => $m];
        }

        usort($timed, static function (array $a, array $b): int {
            if ($a['t'] !== $b['t']) {
                return $a['t'] <=> $b['t'];
            }
            // Prefer non-staff before staff on identical timestamps, then original order.
            if ($a['staff'] !== $b['staff']) {
                return $a['staff'] <=> $b['staff'];
            }
            return $a['ord'] <=> $b['ord'];
        });

        $out = [];
        foreach ($timed as $item) {
            $out[] = $item['m'];
        }
        return self::assignSequentialSeq($out);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private static function assignSequentialSeq(array $messages): array
    {
        $out = [];
        $seq = 1;
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $m['seq'] = $seq++;
            $out[] = $m;
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $messages */
    private static function maxMessageSeq(array $messages): int
    {
        $max = 0;
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $max = max($max, (int) ($m['seq'] ?? 0));
        }
        return $max;
    }

    /** @param array<string, mixed> $message */
    private static function messageFingerprint(array $message): string
    {
        $role = (string) ($message['role'] ?? '');
        $content = trim((string) ($message['content'] ?? ''));
        return $role . "\n" . $content;
    }

    /**
     * Session sync stores live admin replies as assistant "[Human agent — …]: body".
     *
     * @param list<array<string, mixed>> $spine
     */
    private static function matchesExistingAdminEcho(array $assistantMsg, array $spine): bool
    {
        $content = trim((string) ($assistantMsg['content'] ?? ''));
        if (!preg_match('/^\[Human agent[^\]]*\]:\s*(.+)$/s', $content, $m)) {
            return false;
        }
        $body = trim((string) ($m[1] ?? ''));
        if ($body === '') {
            return false;
        }
        foreach ($spine as $e) {
            if (!is_array($e) || ($e['role'] ?? '') !== 'admin') {
                continue;
            }
            if (trim((string) ($e['content'] ?? '')) === $body) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge session display into archive. Prefer session chronological order
     * (including Human-agent echoes mapped back to archive admin rows) so
     * mid-thread live replies stay in place across upserts.
     *
     * @param list<array{role: string, content: string}> $newDisplay
     * @param list<array<string, mixed>> $existingMessages
     * @return list<array<string, mixed>>
     */
    private static function mergeArchiveMessages(array $newDisplay, array $existingMessages): array
    {
        $existing = self::assignSequentialSeq(array_values(array_filter($existingMessages, 'is_array')));
        $adminsByContent = [];
        $notes = [];
        foreach ($existing as $m) {
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'admin') {
                $adminsByContent[$content] = $m;
            } elseif ($role === 'agent_note') {
                $notes[] = $m;
            }
        }

        $out = [];
        $usedAdmin = [];
        $seen = [];
        foreach ($newDisplay as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '' || $role === 'admin' || $role === 'agent_note') {
                continue;
            }

            if ($role === 'assistant' && preg_match('/^\[Human agent[^\]]*\]:\s*(.+)$/s', $content, $hm)) {
                $body = trim((string) ($hm[1] ?? ''));
                if ($body === '') {
                    continue;
                }
                if (isset($adminsByContent[$body])) {
                    $entry = $adminsByContent[$body];
                    $usedAdmin[$body] = true;
                } else {
                    $entry = [
                        'role' => 'admin',
                        'content' => $body,
                        'date' => !empty($m['date']) ? (string) $m['date'] : date('Y-m-d H:i:s'),
                    ];
                }
                $fp = self::messageFingerprint($entry);
                if (isset($seen[$fp])) {
                    continue;
                }
                $out[] = $entry;
                $seen[$fp] = true;
                continue;
            }

            if ($role === 'assistant' && self::matchesExistingAdminEcho($m, $existing)) {
                // Prefer the archive admin row when session still has the echo
                // but we already emitted it above — skip bare duplicates.
                continue;
            }

            $entry = [
                'role' => $role,
                'content' => $content,
                'date' => !empty($m['date']) ? (string) $m['date'] : date('Y-m-d H:i:s'),
            ];
            $fp = self::messageFingerprint($entry);
            if (isset($seen[$fp])) {
                continue;
            }
            $out[] = $entry;
            $seen[$fp] = true;
        }

        foreach ($adminsByContent as $body => $admin) {
            if (!empty($usedAdmin[$body])) {
                continue;
            }
            $fp = self::messageFingerprint($admin);
            if (isset($seen[$fp])) {
                continue;
            }
            $out[] = $admin;
            $seen[$fp] = true;
        }
        foreach ($notes as $note) {
            $fp = self::messageFingerprint($note);
            if (isset($seen[$fp])) {
                continue;
            }
            $out[] = $note;
            $seen[$fp] = true;
        }

        return self::repairMessageOrder($out);
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['open', 'live', 'handed_off', 'resolved'], true) ? $status : 'open';
    }

    /** @return array<string, mixed>|null */
    private static function hydrateRow(array $row, bool $persistRepair = false): array
    {
        $decoded = json_decode((string) ($row['messages_json'] ?? '[]'), true);
        $messages = is_array($decoded) ? $decoded : [];
        $repaired = self::repairMessageOrder(
            $messages,
            (string) ($row['date_created'] ?? ''),
            (string) ($row['date_updated'] ?? '')
        );
        $row['messages'] = $repaired;
        // Persist repaired order when loading a single chat so the UI sticks.
        if (
            $persistRepair
            && $repaired !== []
            && self::messageOrderFingerprint($messages) !== self::messageOrderFingerprint($repaired)
        ) {
            self::persistRepairedMessages((int) ($row['chat_id'] ?? 0), $repaired);
        }
        $row['status'] = self::normalizeStatus((string) ($row['status'] ?? 'open'));
        $row['email_verified'] = (int) ($row['email_verified'] ?? 0);
        $row['awaiting_human'] = self::isAwaitingHuman($row);
        $row['is_archived'] = trim((string) ($row['archived_at'] ?? '')) !== '';
        return $row;
    }

    /** @param list<array<string, mixed>> $messages */
    private static function messageOrderFingerprint(array $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $parts[] = (string) ($m['role'] ?? '') . ':' . substr(md5(trim((string) ($m['content'] ?? ''))), 0, 8);
        }
        return implode('|', $parts);
    }

    /** @param list<array<string, mixed>> $messages */
    private static function persistRepairedMessages(int $chatId, array $messages): void
    {
        global $db;
        if ($chatId < 1 || !isset($db) || !is_object($db)) {
            return;
        }
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . " SET
                messages_json = '" . $db->prepareInput($json) . "',
                message_count = " . count($messages) . "
              WHERE chat_id = " . (int) $chatId . '
              LIMIT 1'
        );
    }

    /**
     * True when a human agent should reply: unclaimed live chats always count;
     * otherwise live/handed_off when the last customer-visible message is from the shopper.
     *
     * @param array<string, mixed> $row
     */
    public static function isAwaitingHuman(array $row): bool
    {
        $status = self::normalizeStatus((string) ($row['status'] ?? 'open'));
        if (!in_array($status, ['handed_off', 'live'], true)) {
            return false;
        }

        // Live handoff waiting for any agent to claim — always treat as awaiting.
        if ($status === 'live' && (int) ($row['assigned_admin_id'] ?? 0) === 0) {
            return true;
        }

        if (!empty($row['messages']) && is_array($row['messages'])) {
            $last = null;
            foreach ($row['messages'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $role = (string) ($m['role'] ?? '');
                if (in_array($role, ['user', 'assistant', 'admin'], true)) {
                    $last = $role;
                }
            }
            return $last === 'user';
        }

        $shopperAt = trim((string) ($row['last_shopper_message_at'] ?? ''));
        $adminAt = trim((string) ($row['last_admin_reply_at'] ?? ''));
        if ($shopperAt === '') {
            // Unclaimed live with no shopper timestamp yet still needs attention.
            return $status === 'live' && (int) ($row['assigned_admin_id'] ?? 0) === 0;
        }
        return $adminAt === '' || strcmp($shopperAt, $adminAt) > 0;
    }

    /**
     * Count chats that need a human (unclaimed live or awaiting reply).
     */
    public static function countAwaitingHuman(): int
    {
        return count(self::awaitingHumanIds());
    }

    /**
     * Chat IDs that need a human — timestamp SQL only (no messages_json hydrate).
     * Matches inboxPriorityOrderSql priority 0 and isAwaitingHuman without messages loaded.
     *
     * @return list<int>
     */
    public static function awaitingHumanIds(): array
    {
        global $db;
        self::ensureTable();
        $ids = [];
        $res = $db->Execute(
            'SELECT chat_id
               FROM ' . self::tableName() . "
              WHERE archived_at IS NULL
                AND (
                  (status = 'live' AND IFNULL(assigned_admin_id, 0) = 0)
                  OR (
                    status IN ('live', 'handed_off')
                    AND last_shopper_message_at IS NOT NULL
                    AND (last_admin_reply_at IS NULL OR last_shopper_message_at > last_admin_reply_at)
                  )
                )
              ORDER BY date_updated DESC"
        );
        while ($res && !$res->EOF) {
            $ids[] = (int) $res->fields['chat_id'];
            $res->MoveNext();
        }
        return $ids;
    }

    /**
     * Reopen a resolved chat: handed_off if it was ever a ticket handoff, else open (AI).
     *
     * @return string|false New status, or false on failure
     */
    public static function reopenChat(int $chatId, int $adminId = 0)
    {
        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }
        if (self::normalizeStatus((string) ($row['status'] ?? '')) !== 'resolved') {
            return false;
        }
        $target = trim((string) ($row['handed_off_at'] ?? '')) !== '' ? 'handed_off' : 'open';
        if (!self::setStatus($chatId, $target, $adminId)) {
            return false;
        }
        return $target;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public static function previewFromMessages(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $text = preg_replace('/\s+/', ' ', (string) $m['content']);
                return self::clip(trim((string) $text), 120);
            }
        }
        if ($messages !== []) {
            $text = preg_replace('/\s+/', ' ', (string) $messages[0]['content']);
            return self::clip(trim((string) $text), 120);
        }
        return '';
    }

    /**
     * @param array{
     *   q?: string,
     *   date_from?: string,
     *   date_to?: string,
     *   guest?: string,
     *   status?: string,
     *   awaiting?: string,
     *   archived?: string,
     *   sort?: string,
     *   dir?: string,
     *   page?: int,
     *   per_page?: int
     * } $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function search(array $filters): array
    {
        global $db;
        self::ensureTable();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $safe = $db->prepareInput($q);
            $where[] = "(customer_email LIKE '%{$safe}%'
                OR customer_name LIKE '%{$safe}%'
                OR session_id LIKE '%{$safe}%'
                OR preview LIKE '%{$safe}%'
                OR CAST(customers_id AS CHAR) = '" . $safe . "')";
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = "date_updated >= '" . $db->prepareInput($dateFrom) . " 00:00:00'";
        }
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = "date_updated <= '" . $db->prepareInput($dateTo) . " 23:59:59'";
        }

        $guest = (string) ($filters['guest'] ?? '');
        if ($guest === '1' || $guest === '0') {
            $where[] = 'is_guest = ' . (int) $guest;
        }

        $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $status = self::normalizeStatus($statusFilter);
            if (in_array($status, ['open', 'live', 'handed_off', 'resolved'], true)) {
                $where[] = "status = '" . $db->prepareInput($status) . "'";
            }
        }

        $awaiting = (string) ($filters['awaiting'] ?? '');
        if ($awaiting === '1') {
            // Same criteria as the banner count / inboxPriorityOrderSql priority 0.
            $awaitingIds = self::awaitingHumanIds();
            if ($awaitingIds === []) {
                $where[] = '1=0';
            } else {
                $where[] = 'chat_id IN (' . implode(',', array_map('intval', $awaitingIds)) . ')';
            }
        }

        $assignedAdminId = (string) ($filters['assigned_admin_id'] ?? '');
        if ($assignedAdminId === 'me' || $assignedAdminId === 'self') {
            $me = (int) ($filters['current_admin_id'] ?? 0);
            if ($me > 0) {
                $where[] = 'assigned_admin_id = ' . $me;
            } else {
                $where[] = '1=0';
            }
        } elseif ($assignedAdminId === '0' || $assignedAdminId === 'unassigned') {
            $where[] = 'IFNULL(assigned_admin_id, 0) = 0'
                . " AND IFNULL(assigned_seekmodo_user_id, '') = ''";
        } elseif ($assignedAdminId !== '' && ctype_digit($assignedAdminId)) {
            $where[] = 'assigned_admin_id = ' . (int) $assignedAdminId;
        }

        $assignedSeekmodo = trim((string) ($filters['assigned_seekmodo_user_id'] ?? ''));
        if ($assignedSeekmodo === 'unassigned') {
            $where[] = "IFNULL(assigned_seekmodo_user_id, '') = ''";
        } elseif ($assignedSeekmodo !== '') {
            $where[] = "assigned_seekmodo_user_id = '" . $db->prepareInput(mb_substr($assignedSeekmodo, 0, 64)) . "'";
        }

        // Inbox excludes archived; archive page lists only archived.
        $emailExact = strtolower(trim((string) ($filters['customer_email'] ?? '')));
        if ($emailExact !== '' && filter_var($emailExact, FILTER_VALIDATE_EMAIL)) {
            $where[] = "customer_email = '" . $db->prepareInput($emailExact) . "'";
        }

        $archived = (string) ($filters['archived'] ?? '0');
        if ($archived === 'all') {
            // Shopper history includes resolved and archived rows.
        } elseif ($archived === '1') {
            $where[] = 'archived_at IS NOT NULL';
        } else {
            $where[] = 'archived_at IS NULL';
        }

        $sortMap = [
            'updated' => 'date_updated',
            'created' => 'date_created',
            'email' => 'customer_email',
            'messages' => 'message_count',
            'archived' => 'archived_at',
        ];
        $sortKey = (string) ($filters['sort'] ?? 'priority');
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        // Default / priority: awaiting → live → open/handed_off → resolved, then updated.
        if ($sortKey === 'priority' || $sortKey === '' || $sortKey === 'updated') {
            $orderSql = self::inboxPriorityOrderSql() . ', date_updated ' . $dir;
        } else {
            $sortCol = $sortMap[$sortKey] ?? 'date_updated';
            $orderSql = $sortCol . ' ' . $dir . ', ' . self::inboxPriorityOrderSql();
        }

        $table = self::tableName();
        $whereSql = implode(' AND ', $where);

        $countRes = $db->Execute('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $whereSql);
        $total = ($countRes && !$countRes->EOF) ? (int) $countRes->fields['c'] : 0;
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $rows = [];
        $res = $db->Execute(
            'SELECT chat_id, session_id, customers_id, customer_email, customer_name, is_guest,
                    preview, message_count, tickets_sent, status, date_created, date_updated,
                    last_shopper_message_at, last_admin_reply_at, shopper_presence_at, handed_off_at,
                    assigned_admin_id, claimed_at, archived_at, live_requested_at,
                    assigned_seekmodo_user_id, assigned_seekmodo_name, assigned_seekmodo_email
               FROM ' . $table . '
              WHERE ' . $whereSql . '
              ORDER BY ' . $orderSql . '
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        while ($res && !$res->EOF) {
            $row = $res->fields;
            // List SELECT omits messages_json; when awaiting=1 the IN() clause already
            // used isAwaitingHuman, so mark those rows true. Otherwise use timestamps.
            if ($awaiting === '1') {
                $row['awaiting_human'] = true;
            } else {
                $row['awaiting_human'] = self::isAwaitingHuman($row);
            }
            $row['is_archived'] = trim((string) ($row['archived_at'] ?? '')) !== '';
            $rows[] = $row;
            $res->MoveNext();
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * SQL fragment: lower number = higher on the inbox.
     * 0 = awaiting human, 1 = live, 2 = open/handed_off, 3 = resolved.
     */
    private static function inboxPriorityOrderSql(): string
    {
        return "CASE
            WHEN status = 'live' AND IFNULL(assigned_admin_id, 0) = 0 THEN 0
            WHEN status IN ('live', 'handed_off')
                 AND last_shopper_message_at IS NOT NULL
                 AND (last_admin_reply_at IS NULL OR last_shopper_message_at > last_admin_reply_at) THEN 0
            WHEN status = 'live' THEN 1
            WHEN status IN ('open', 'handed_off') THEN 2
            WHEN status = 'resolved' THEN 3
            ELSE 4
        END ASC";
    }

    public static function archiveChat(int $chatId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . '
                SET archived_at = IFNULL(archived_at, NOW()),
                    date_updated = date_updated
              WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    public static function unarchiveChat(int $chatId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . '
                SET archived_at = NULL,
                    date_updated = date_updated
              WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /** @param list<int|string> $chatIds */
    public static function archiveMany(array $chatIds): int
    {
        $n = 0;
        foreach ($chatIds as $id) {
            if (self::archiveChat((int) $id)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Soft-archive resolved inbox chats older than $days (based on resolved_at,
     * falling back to date_updated). Does not delete transcripts; Unarchive restores them.
     * Returns the number of chats archived. Days less than 1 disables (returns 0).
     */
    public static function archiveResolvedOlderThan(int $days, int $limit = 500): int
    {
        global $db;
        self::ensureTable();
        if ($days < 1) {
            return 0;
        }
        $limit = max(1, min(2000, $limit));
        $ids = [];
        $res = $db->Execute(
            'SELECT chat_id FROM ' . self::tableName() . "
              WHERE status = 'resolved'
                AND archived_at IS NULL
                AND COALESCE(resolved_at, date_updated) <= DATE_SUB(NOW(), INTERVAL " . (int) $days . ' DAY)
              ORDER BY COALESCE(resolved_at, date_updated) ASC
              LIMIT ' . (int) $limit
        );
        while ($res && !$res->EOF) {
            $ids[] = (int) $res->fields['chat_id'];
            $res->MoveNext();
        }
        if ($ids === []) {
            return 0;
        }
        return self::archiveMany($ids);
    }

    /** @param list<int|string> $chatIds */
    public static function unarchiveMany(array $chatIds): int
    {
        $n = 0;
        foreach ($chatIds as $id) {
            if (self::unarchiveChat((int) $id)) {
                $n++;
            }
        }
        return $n;
    }

    public static function isArchived(array $row): bool
    {
        return trim((string) ($row['archived_at'] ?? '')) !== '';
    }

    /** @return array<string, mixed>|null */
    public static function getById(int $chatId): ?array
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return null;
        }
        $res = $db->Execute(
            'SELECT * FROM ' . self::tableName() . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        if (!$res || $res->EOF) {
            return null;
        }
        return self::hydrateRow($res->fields, true);
    }

    /** @return array<string, mixed>|null */
    public static function getBySessionId(string $sessionId): ?array
    {
        global $db;
        self::ensureTable();
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($sessionId === '') {
            return null;
        }
        $res = $db->Execute(
            'SELECT * FROM ' . self::tableName()
            . " WHERE session_id = '" . $db->prepareInput($sessionId) . "' LIMIT 1"
        );
        if (!$res || $res->EOF) {
            return null;
        }
        return self::hydrateRow($res->fields, true);
    }

    public static function setStatus(int $chatId, string $status, int $adminId = 0): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $status = self::normalizeStatus($status);
        if (!in_array($status, ['open', 'live', 'handed_off', 'resolved'], true)) {
            return false;
        }

        $sets = [
            "status = '" . $db->prepareInput($status) . "'",
            'date_updated = NOW()',
        ];
        if ($status === 'resolved') {
            $sets[] = 'resolved_at = NOW()';
            $sets[] = 'resolved_by_admin_id = ' . max(0, (int) $adminId);
            $sets[] = 'live_requested_at = NULL';
        } elseif ($status === 'open') {
            $sets[] = 'resolved_at = NULL';
            $sets[] = 'resolved_by_admin_id = 0';
            $sets[] = 'assigned_admin_id = 0';
            $sets[] = 'claimed_at = NULL';
            $sets[] = 'live_requested_at = NULL';
        } elseif ($status === 'live') {
            $sets[] = 'resolved_at = NULL';
            $sets[] = 'resolved_by_admin_id = 0';
            if ($adminId > 0) {
                $sets[] = 'assigned_admin_id = ' . (int) $adminId;
                $sets[] = 'claimed_at = IFNULL(claimed_at, NOW())';
                $sets[] = 'live_requested_at = NULL';
            } else {
                $sets[] = 'live_requested_at = IFNULL(live_requested_at, NOW())';
            }
        } elseif ($status === 'handed_off') {
            $sets[] = 'handed_off_at = IFNULL(handed_off_at, NOW())';
            $sets[] = 'live_requested_at = NULL';
        }

        $db->Execute(
            'UPDATE ' . self::tableName() . ' SET ' . implode(', ', $sets)
            . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    public static function claimChat(int $chatId, int $adminId): bool
    {
        if ($chatId < 1 || $adminId < 1) {
            return false;
        }
        return self::setStatus($chatId, 'live', $adminId);
    }

    public static function resumeAi(int $chatId, int $adminId = 0): bool
    {
        return self::setStatus($chatId, 'open', $adminId);
    }

    /**
     * Seconds to wait for a human claim after live escalation (Seekmodo-published
     * or local constant). Default 45; clamped 15–300.
     */
    public static function escalationWaitSeconds(): int
    {
        $seconds = 45;
        if (defined('SEEKMODO_AI_CHATBOT_ESCALATION_WAIT_SECONDS')) {
            $seconds = (int) SEEKMODO_AI_CHATBOT_ESCALATION_WAIT_SECONDS;
        }
        return max(15, min(300, $seconds));
    }

    /**
     * True when unclaimed live handoff has exceeded the escalation wait.
     *
     * @param array<string, mixed> $row
     */
    public static function isLiveEscalationTimedOut(array $row): bool
    {
        $status = self::normalizeStatus((string) ($row['status'] ?? 'open'));
        if ($status !== 'live' || (int) ($row['assigned_admin_id'] ?? 0) > 0) {
            return false;
        }
        $raw = trim((string) ($row['live_requested_at'] ?? ''));
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            // Legacy rows: fall back to date_updated when status is live unclaimed.
            $raw = trim((string) ($row['date_updated'] ?? ''));
        }
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return false;
        }
        $ts = strtotime($raw);
        if ($ts === false || $ts <= 0) {
            return false;
        }
        return (time() - $ts) >= self::escalationWaitSeconds();
    }

    /**
     * Append a customer-visible assistant message (e.g. escalation timeout notice).
     */
    public static function appendAssistantMessage(int $chatId, string $content, array $meta = []): bool
    {
        return self::appendChatMessage($chatId, 'assistant', $content, array_merge([
            'channel' => 'system',
            'preserve_status' => !empty($meta['preserve_status']),
            'status' => (string) ($meta['status'] ?? 'open'),
        ], $meta));
    }

    /**
     * Assign to a Zen Cart admin (store Inbox). Works for live and handed_off tickets.
     */
    public static function assignToAdmin(int $chatId, int $adminId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1 || $adminId < 1) {
            return false;
        }
        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }
        $status = self::normalizeStatus((string) ($row['status'] ?? 'open'));
        if ($status === 'resolved') {
            return false;
        }
        // Keep handed_off as ticket; promote open → live when assigning for chat.
        $newStatus = $status === 'handed_off' ? 'handed_off' : 'live';
        $sets = [
            "status = '" . $db->prepareInput($newStatus) . "'",
            'assigned_admin_id = ' . (int) $adminId,
            'claimed_at = IFNULL(claimed_at, NOW())',
            'live_requested_at = NULL',
            'date_updated = NOW()',
            "assigned_seekmodo_user_id = ''",
            "assigned_seekmodo_name = ''",
            "assigned_seekmodo_email = ''",
        ];
        $db->Execute(
            'UPDATE ' . self::tableName() . ' SET ' . implode(', ', $sets)
            . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /**
     * Assign to a Seekmodo tenant teammate (cloud Inbox); mirrored onto the store row.
     */
    public static function assignToSeekmodoUser(
        int $chatId,
        string $userId,
        string $name = '',
        string $email = ''
    ): bool {
        global $db;
        self::ensureTable();
        $userId = trim($userId);
        if ($chatId < 1 || $userId === '') {
            return false;
        }
        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }
        $status = self::normalizeStatus((string) ($row['status'] ?? 'open'));
        if ($status === 'resolved') {
            return false;
        }
        $newStatus = $status === 'handed_off' ? 'handed_off' : 'live';
        $sets = [
            "status = '" . $db->prepareInput($newStatus) . "'",
            "assigned_seekmodo_user_id = '" . $db->prepareInput(mb_substr($userId, 0, 64)) . "'",
            "assigned_seekmodo_name = '" . $db->prepareInput(self::clip(trim($name), 128)) . "'",
            "assigned_seekmodo_email = '" . $db->prepareInput(self::clip(strtolower(trim($email)), 175)) . "'",
            'live_requested_at = NULL',
            'date_updated = NOW()',
        ];
        // Do not wipe Zen assignee if already claimed in-store; cloud assign is additive label.
        if ((int) ($row['assigned_admin_id'] ?? 0) < 1 && $newStatus === 'live') {
            $sets[] = 'claimed_at = IFNULL(claimed_at, NOW())';
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . ' SET ' . implode(', ', $sets)
            . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /**
     * Clear Seekmodo cloud assignee fields (unassign). Leaves status / Zen assignee alone.
     */
    public static function clearSeekmodoAssignee(int $chatId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . " SET
                assigned_seekmodo_user_id = '',
                assigned_seekmodo_name = '',
                assigned_seekmodo_email = '',
                date_updated = NOW()
             WHERE chat_id = " . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function listStoreAssignees(): array
    {
        if (function_exists('zen_get_users')) {
            $users = zen_get_users();
            $out = [];
            if (is_array($users)) {
                foreach ($users as $u) {
                    if (!is_array($u)) {
                        continue;
                    }
                    $id = (int) ($u['id'] ?? $u['admin_id'] ?? 0);
                    if ($id < 1) {
                        continue;
                    }
                    $out[] = [
                        'id' => $id,
                        'name' => trim((string) ($u['name'] ?? $u['admin_name'] ?? ('Admin #' . $id))),
                        'email' => strtolower(trim((string) ($u['email'] ?? $u['admin_email'] ?? ''))),
                    ];
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        global $db;
        if (!isset($db) || !defined('TABLE_ADMIN')) {
            return [];
        }
        $out = [];
        $res = $db->Execute(
            'SELECT admin_id, admin_name, admin_email FROM ' . TABLE_ADMIN . ' ORDER BY admin_name ASC'
        );
        while ($res && !$res->EOF) {
            $out[] = [
                'id' => (int) ($res->fields['admin_id'] ?? 0),
                'name' => trim((string) ($res->fields['admin_name'] ?? '')),
                'email' => strtolower(trim((string) ($res->fields['admin_email'] ?? ''))),
            ];
            $res->MoveNext();
        }
        return array_values(array_filter($out, static fn ($r) => $r['id'] > 0));
    }

    /**
     * Customer-visible admin reply for live in-widget chat (no email required).
     */
    public static function appendLiveAdminMessage(int $chatId, string $content, int $adminId, string $adminName = ''): bool
    {
        return self::appendChatMessage($chatId, 'admin', $content, [
            'admin_id' => max(0, (int) $adminId),
            'admin_name' => $adminName,
            'channel' => 'live',
            'status' => 'live',
        ]);
    }

    /**
     * Internal agent note — never sent to the storefront widget.
     */
    public static function appendAgentNote(int $chatId, string $content, int $adminId, string $adminName = ''): bool
    {
        return self::appendChatMessage($chatId, 'agent_note', $content, [
            'admin_id' => max(0, (int) $adminId),
            'admin_name' => $adminName,
            'channel' => 'internal',
            'preserve_status' => true,
        ]);
    }

    /**
     * Shopper message while a human owns the chat (skip LLM).
     */
    public static function appendLiveShopperMessage(int $chatId, string $content): bool
    {
        return self::appendChatMessage($chatId, 'user', $content, [
            'channel' => 'live',
            'status' => 'live',
            'touch_shopper' => true,
        ]);
    }

    public static function appendAdminMessage(int $chatId, string $content, int $adminId): bool
    {
        return self::appendInboundMessage($chatId, 'admin', $content, [
            'admin_id' => max(0, (int) $adminId),
        ]);
    }

    /**
     * Append a ticket message from email/admin UI (email handoff path).
     *
     * @param array<string, mixed> $meta
     */
    public static function appendInboundMessage(int $chatId, string $role, string $content, array $meta = []): bool
    {
        $meta['status'] = 'handed_off';
        $meta['channel'] = $meta['channel'] ?? 'email';
        return self::appendChatMessage($chatId, $role === 'admin' ? 'admin' : 'user', $content, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function appendChatMessage(int $chatId, string $role, string $content, array $meta = []): bool
    {
        global $db;
        self::ensureTable();
        $content = trim($content);
        $allowedRoles = ['admin', 'user', 'agent_note', 'assistant'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'user';
        }
        if ($chatId < 1 || $content === '') {
            return false;
        }

        $row = self::getById($chatId);
        if ($row === null) {
            return false;
        }

        $messages = is_array($row['messages'] ?? null) ? $row['messages'] : [];
        $messages = self::ensureMessageSequences($messages);
        $entry = [
            'role' => $role,
            'content' => $content,
            'date' => date('Y-m-d H:i:s'),
            'seq' => self::maxMessageSeq($messages) + 1,
        ];
        if (in_array($role, ['admin', 'agent_note'], true)) {
            $entry['admin_id'] = max(0, (int) ($meta['admin_id'] ?? 0));
            if (!empty($meta['admin_name'])) {
                $entry['admin_name'] = (string) $meta['admin_name'];
            }
        }
        if (!empty($meta['channel'])) {
            $entry['channel'] = (string) $meta['channel'];
        }
        if (!empty($meta['from_email'])) {
            $entry['from_email'] = strtolower(trim((string) $meta['from_email']));
        }
        if (!empty($meta['inbound_message_id'])) {
            $entry['inbound_message_id'] = (string) $meta['inbound_message_id'];
        }
        $messages[] = $entry;
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return false;
        }

        $preview = self::previewFromMessages($messages);
        $sets = [
            "messages_json = '" . $db->prepareInput($json) . "'",
            'message_count = ' . count($messages),
            "preview = '" . $db->prepareInput(self::clip($preview, 255)) . "'",
            'resolved_at = NULL',
            'resolved_by_admin_id = 0',
            'date_updated = NOW()',
        ];

        if (empty($meta['preserve_status'])) {
            $status = self::normalizeStatus((string) ($meta['status'] ?? 'handed_off'));
            $sets[] = "status = '" . $db->prepareInput($status) . "'";
            if ($status === 'handed_off') {
                $sets[] = 'handed_off_at = IFNULL(handed_off_at, NOW())';
            }
            if ($status === 'live' && !empty($meta['admin_id'])) {
                $sets[] = 'assigned_admin_id = ' . (int) $meta['admin_id'];
                $sets[] = 'claimed_at = IFNULL(claimed_at, NOW())';
            }
        }

        if ($role === 'admin') {
            $sets[] = 'last_admin_reply_at = NOW()';
        }
        if (!empty($meta['touch_shopper']) || $role === 'user') {
            $sets[] = 'last_shopper_message_at = NOW()';
        }
        $db->Execute(
            'UPDATE ' . self::tableName() . ' SET ' . implode(', ', $sets)
            . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1'
        );
        return true;
    }

    /**
     * Public (shopper-facing) messages — excludes agent_note.
     *
     * @param array<string, mixed>|null $chat
     * @return list<array<string, mixed>>
     */
    public static function publicMessages(?array $chat): array
    {
        if ($chat === null) {
            return [];
        }
        $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (($m['role'] ?? '') === 'agent_note') {
                continue;
            }
            $out[] = $m;
        }
        return $out;
    }

    public static function assigneeDisplayName(int $adminId): string
    {
        global $db;
        if ($adminId < 1 || !isset($db) || !defined('TABLE_ADMIN')) {
            return $adminId > 0 ? ('Agent #' . $adminId) : '';
        }
        $res = $db->Execute(
            'SELECT admin_name FROM ' . TABLE_ADMIN . ' WHERE admin_id = ' . (int) $adminId . ' LIMIT 1'
        );
        if ($res && !$res->EOF) {
            $name = trim((string) ($res->fields['admin_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return 'Agent #' . $adminId;
    }

    public static function setLastOutboundMessageId(int $chatId, string $messageId): void
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return;
        }
        $messageId = trim($messageId);
        $db->Execute(
            'UPDATE ' . self::tableName() . " SET
                last_outbound_message_id = '" . $db->prepareInput($messageId) . "',
                date_updated = date_updated
              WHERE chat_id = " . (int) $chatId . ' LIMIT 1'
        );
    }

    /**
     * Open a handed-off email ticket that did not match an existing #id.
     * Returns the new chat_id, or 0 when the insert fails.
     */
    public static function createEmailTicket(
        string $email,
        string $name,
        string $subject,
        string $body,
        string $messageId = ''
    ): int {
        global $db;
        if (!isset($db) || !is_object($db) || !self::ensureTable()) {
            return 0;
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $name = trim($name);
        $subject = trim($subject);
        $body = trim($body);
        if ($body === '') {
            $body = $subject !== '' ? $subject : '(No message body.)';
        }
        $content = $subject !== '' ? ("Subject: {$subject}\n\n{$body}") : $body;

        $customersId = self::lookupCustomersIdByEmail($email);
        $isGuest = $customersId > 0 ? 0 : 1;
        if ($name === '' && $customersId > 0) {
            $lookedUp = self::lookupCustomer($customersId);
            if ($lookedUp !== null && $lookedUp['name'] !== '') {
                $name = $lookedUp['name'];
            }
        }

        $sessionId = 'email-' . bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        $messages = [[
            'role' => 'user',
            'content' => $content,
            'date' => $now,
            'seq' => 1,
            'channel' => 'email',
            'from_email' => $email,
        ]];
        if ($messageId !== '') {
            $messages[0]['inbound_message_id'] = $messageId;
        }
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return 0;
        }
        $preview = self::clip($content, 255);
        $table = self::tableName();
        $db->Execute(
            'INSERT INTO ' . $table . " (
                session_id, customers_id, customer_email, email_verified, customer_name, is_guest,
                preview, message_count, tickets_sent, messages_json, status,
                handed_off_at, last_shopper_message_at, date_created, date_updated
             ) VALUES (
                '" . $db->prepareInput($sessionId) . "',
                " . (int) $customersId . ",
                '" . $db->prepareInput($email) . "',
                1,
                '" . $db->prepareInput(self::clip($name, 128)) . "',
                " . (int) $isGuest . ",
                '" . $db->prepareInput($preview) . "',
                1,
                0,
                '" . $db->prepareInput($json) . "',
                'handed_off',
                '" . $db->prepareInput($now) . "',
                '" . $db->prepareInput($now) . "',
                '" . $db->prepareInput($now) . "',
                '" . $db->prepareInput($now) . "'
             )"
        );
        $id = method_exists($db, 'Insert_ID') ? (int) $db->Insert_ID() : 0;
        if ($id < 1) {
            $row = self::getBySessionId($sessionId);
            $id = $row !== null ? (int) ($row['chat_id'] ?? 0) : 0;
        }
        return $id;
    }

    /** @return array<string, mixed>|null */
    public static function findOpenByEmail(string $email): ?array
    {
        global $db;
        self::ensureTable();
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $res = $db->Execute(
            'SELECT * FROM ' . self::tableName()
            . " WHERE customer_email = '" . $db->prepareInput($email) . "'"
            . " AND status IN ('open', 'handed_off')"
            . ' ORDER BY date_updated DESC LIMIT 1'
        );
        if (!$res || $res->EOF) {
            return null;
        }
        return self::hydrateRow($res->fields);
    }

    public static function countOpenTickets(): int
    {
        global $db;
        self::ensureTable();
        $res = $db->Execute(
            'SELECT COUNT(*) AS c FROM ' . self::tableName()
            . " WHERE status IN ('open', 'live', 'handed_off')
                AND archived_at IS NULL"
        );
        return ($res && !$res->EOF) ? (int) $res->fields['c'] : 0;
    }

    /**
     * Chats that went idle without ever being explicitly resolved/closed —
     * candidates for the nightly post-chat-analysis catch-up. Excludes any
     * chat_id already present in $excludeChatIds (already queued/analyzed).
     *
     * @param list<int> $excludeChatIds
     * @return list<array{chat_id: int, message_count: int, date_updated: string}>
     */
    public static function listIdleUnresolved(int $idleMinutes, int $minMessages, array $excludeChatIds = [], int $limit = 100): array
    {
        global $db;
        self::ensureTable();
        $idleMinutes = max(1, $idleMinutes);
        $minMessages = max(1, $minMessages);
        $limit = max(1, min(500, $limit));

        $where = [
            "status IN ('open', 'handed_off')",
            'message_count >= ' . (int) $minMessages,
            'date_updated <= DATE_SUB(NOW(), INTERVAL ' . (int) $idleMinutes . ' MINUTE)',
        ];
        $excludeChatIds = array_values(array_unique(array_filter(array_map('intval', $excludeChatIds), static fn ($id) => $id > 0)));
        if ($excludeChatIds !== []) {
            $where[] = 'chat_id NOT IN (' . implode(',', $excludeChatIds) . ')';
        }

        $rows = [];
        $res = $db->Execute(
            'SELECT chat_id, message_count, date_updated FROM ' . self::tableName() . '
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY date_updated ASC
              LIMIT ' . (int) $limit
        );
        while ($res && !$res->EOF) {
            $rows[] = [
                'chat_id' => (int) $res->fields['chat_id'],
                'message_count' => (int) $res->fields['message_count'],
                'date_updated' => (string) $res->fields['date_updated'],
            ];
            $res->MoveNext();
        }
        return $rows;
    }

    /**
     * Recently resolved chats for post-chat-analysis catch-up when the
     * resolve-time MCP enqueue failed (chat is already resolved so
     * listIdleUnresolved cannot see it).
     *
     * @param list<int> $excludeChatIds
     * @return list<array{chat_id: int, message_count: int, date_updated: string}>
     */
    public static function listResolvedForAnalysis(int $minMessages, array $excludeChatIds = [], int $limit = 100, int $withinDays = 14): array
    {
        global $db;
        self::ensureTable();
        $minMessages = max(1, $minMessages);
        $limit = max(1, min(500, $limit));
        $withinDays = max(1, min(90, $withinDays));

        $where = [
            "status = 'resolved'",
            'message_count >= ' . (int) $minMessages,
            'resolved_at IS NOT NULL',
            'resolved_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $withinDays . ' DAY)',
        ];
        $excludeChatIds = array_values(array_unique(array_filter(array_map('intval', $excludeChatIds), static fn ($id) => $id > 0)));
        if ($excludeChatIds !== []) {
            $where[] = 'chat_id NOT IN (' . implode(',', $excludeChatIds) . ')';
        }

        $rows = [];
        $res = $db->Execute(
            'SELECT chat_id, message_count, date_updated FROM ' . self::tableName() . '
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY resolved_at ASC
              LIMIT ' . (int) $limit
        );
        while ($res && !$res->EOF) {
            $rows[] = [
                'chat_id' => (int) $res->fields['chat_id'],
                'message_count' => (int) $res->fields['message_count'],
                'date_updated' => (string) $res->fields['date_updated'],
            ];
            $res->MoveNext();
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOpenForDashboard(int $limit = 10): array
    {
        global $db;
        self::ensureTable();
        $limit = max(1, min(50, $limit));
        $rows = [];
        $res = $db->Execute(
            'SELECT chat_id, session_id, customer_email, customer_name, preview, status,
                    message_count, date_updated, date_created,
                    last_shopper_message_at, last_admin_reply_at, shopper_presence_at,
                    assigned_admin_id, claimed_at,
                    assigned_seekmodo_user_id, assigned_seekmodo_name, assigned_seekmodo_email
               FROM ' . self::tableName() . "
              WHERE status IN ('open', 'live', 'handed_off')
                AND archived_at IS NULL
              ORDER BY date_updated DESC
              LIMIT 80"
        );
        while ($res && !$res->EOF) {
            $row = $res->fields;
            $row['status'] = self::normalizeStatus((string) ($row['status'] ?? 'open'));
            $row['awaiting_human'] = self::isAwaitingHuman($row);
            $row['is_archived'] = false;
            $rows[] = $row;
            $res->MoveNext();
        }
        // Awaiting / unclaimed live first, then other live, then rest by updated.
        usort($rows, static function (array $a, array $b): int {
            $score = static function (array $r): int {
                if (!empty($r['awaiting_human'])) {
                    return 0;
                }
                $st = (string) ($r['status'] ?? '');
                if ($st === 'live') {
                    return 1;
                }
                if ($st === 'handed_off') {
                    return 2;
                }
                return 3;
            };
            $cmp = $score($a) <=> $score($b);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($b['date_updated'] ?? ''), (string) ($a['date_updated'] ?? ''));
        });
        return array_slice($rows, 0, $limit);
    }

    public static function inboundAlreadyProcessed(string $folder, int $uid, string $messageId): bool
    {
        global $db;
        self::ensureTable();
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
        $table = self::inboundTableName();
        if ($uid > 0) {
            $res = $db->Execute(
                'SELECT id FROM `' . str_replace('`', '``', $table) . '`'
                . " WHERE folder = '" . $db->prepareInput($folder) . "' AND imap_uid = " . (int) $uid
                . ' LIMIT 1'
            );
            if ($res && !$res->EOF) {
                return true;
            }
        }
        $messageId = trim($messageId);
        if ($messageId !== '') {
            $res = $db->Execute(
                'SELECT id FROM `' . str_replace('`', '``', $table) . '`'
                . " WHERE rfc_message_id = '" . $db->prepareInput($messageId) . "' LIMIT 1"
            );
            if ($res && !$res->EOF) {
                return true;
            }
        }
        return false;
    }

    public static function markInboundProcessed(string $folder, int $uid, string $messageId, int $chatId): void
    {
        global $db;
        self::ensureTable();
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
        $table = self::inboundTableName();
        $db->Execute(
            'INSERT IGNORE INTO `' . str_replace('`', '``', $table) . '`
                (folder, imap_uid, rfc_message_id, chat_id, date_processed)
             VALUES (
                \'' . $db->prepareInput($folder) . '\',
                ' . (int) $uid . ',
                \'' . $db->prepareInput(trim($messageId)) . '\',
                ' . max(0, (int) $chatId) . ',
                NOW()
             )'
        );
    }

    /**
     * Active chats for a shopper (excludes resolved) — used for Recent Conversations.
     *
     * @return list<array<string, mixed>>
     */
    public static function listForShopper(int $customersId, string $email = '', bool $includeResolved = false): array
    {
        global $db;
        self::ensureTable();
        $customersId = max(0, (int) $customersId);
        $email = strtolower(trim($email));

        $where = $includeResolved
            ? ["status IN ('open', 'live', 'handed_off', 'resolved')"]
            : ["status IN ('open', 'live', 'handed_off')"];
        if ($customersId > 0) {
            $where[] = 'customers_id = ' . $customersId;
        } elseif ($email !== '') {
            $where[] = "customer_email = '" . $db->prepareInput($email) . "'";
        } else {
            return [];
        }

        $rows = [];
        $res = $db->Execute(
            'SELECT chat_id, session_id, customers_id, customer_email, customer_name, preview,
                    message_count, status, date_created, date_updated, messages_json,
                    handed_off_at, last_shopper_message_at, last_admin_reply_at
               FROM ' . self::tableName() . '
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY date_updated DESC
              LIMIT 50'
        );
        while ($res && !$res->EOF) {
            $rows[] = self::hydrateRow($res->fields);
            $res->MoveNext();
        }
        return $rows;
    }

    /**
     * All tickets/chats for a shopper including resolved (widget My tickets).
     *
     * @return list<array<string, mixed>>
     */
    public static function listTicketsForShopper(int $customersId, string $email = ''): array
    {
        return self::listForShopper($customersId, $email, true);
    }

    /**
     * Admin list helper with status filter.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public static function listAdmin(array $filters): array
    {
        return self::search($filters);
    }

    /** @return array<string, mixed>|null */
    public static function get(int $chatId): ?array
    {
        return self::getById($chatId);
    }

    public static function delete(int $chatId): bool
    {
        global $db;
        self::ensureTable();
        if ($chatId < 1) {
            return false;
        }
        $db->Execute('DELETE FROM ' . self::tableName() . ' WHERE chat_id = ' . (int) $chatId . ' LIMIT 1');
        return true;
    }

    /** @param list<int> $chatIds */
    public static function deleteMany(array $chatIds): int
    {
        global $db;
        self::ensureTable();
        $ids = array_values(array_unique(array_filter(array_map('intval', $chatIds), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $db->Execute(
            'DELETE FROM ' . self::tableName() . ' WHERE chat_id IN (' . implode(',', $ids) . ')'
        );
        return count($ids);
    }

    /**
     * Import currently present session JSON files into the archive (best-effort).
     */
    public static function importSessionFiles(): int
    {
        $dir = ChatAgent::sessionsDirectory();
        if (!is_dir($dir)) {
            return 0;
        }
        $imported = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $sessionId = basename($file, '.json');
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
                continue;
            }
            self::upsert(
                $sessionId,
                $data['messages'],
                [
                    'logged_in' => false,
                    'customers_id' => 0,
                    'email' => '',
                    'first_name' => '',
                ],
                [
                    'created' => (int) ($data['created'] ?? time()),
                    'updated' => (int) ($data['updated'] ?? time()),
                    'tickets_sent' => (int) ($data['tickets_sent'] ?? 0),
                ]
            );
            $imported++;
        }
        return $imported;
    }
}

<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Tenant-editable description of what this store sells.
 *
 * Injected into the chatbot system prompt so the model stays inside
 * the catalog. Auto-built from STORE_NAME + top categories when empty;
 * merchants can edit SEEKMODO_AI_CHATBOT_STORE_CONTEXT in admin.
 */
final class StoreContext
{
    public static function configured(): string
    {
        if (!defined('SEEKMODO_AI_CHATBOT_STORE_CONTEXT')) {
            return '';
        }
        return trim((string) SEEKMODO_AI_CHATBOT_STORE_CONTEXT);
    }

    /**
     * Prefer saved admin text; fall back to a live catalog draft so
     * stores get context even before they open settings.
     */
    public static function forPrompt(): string
    {
        $saved = self::configured();
        if ($saved !== '') {
            return $saved;
        }
        return self::buildFromCatalog();
    }

    /**
     * Draft store context from Zen Cart config + category tree.
     * Safe ASCII punctuation for latin1 configuration_value columns.
     */
    public static function buildFromCatalog(int $maxCategories = 40): string
    {
        $storeName = defined('STORE_NAME') ? trim((string) STORE_NAME) : '';
        if ($storeName === '') {
            $storeName = 'this store';
        }

        $host = '';
        if (defined('HTTPS_SERVER') && trim((string) HTTPS_SERVER) !== '') {
            $host = preg_replace('#^https?://#i', '', trim((string) HTTPS_SERVER)) ?? '';
        } elseif (defined('HTTP_SERVER') && trim((string) HTTP_SERVER) !== '') {
            $host = preg_replace('#^https?://#i', '', trim((string) HTTP_SERVER)) ?? '';
        }
        $host = strtolower(rtrim($host, '/'));

        $lines = [];
        $lines[] = 'Store name: ' . self::asciiSafe($storeName);
        if ($host !== '') {
            $lines[] = 'Storefront: https://' . self::asciiSafe($host);
        }
        $lines[] = 'This assistant only represents this store\'s own catalog and policies.';
        $lines[] = 'Recommend only products and category pages returned by store search tools — never invent product types from general knowledge.';

        $categories = self::topCategoryNames($maxCategories);
        if ($categories !== []) {
            $lines[] = 'Main categories / ranges sold:';
            foreach ($categories as $name) {
                $lines[] = '- ' . self::asciiSafe($name);
            }
        } else {
            $lines[] = 'Catalog focus: personalised gifts and products sold on this storefront (confirm via search tools).';
        }

        $lines[] = 'If search finds nothing useful: say so, ask one clarifying question, and search again. Do not suggest blankets, gardening tools, jewellery, photo albums, tea sets, or any other generic gift ideas that are not in tool results.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function topCategoryNames(int $limit): array
    {
        global $db;
        if (!isset($db) || !is_object($db)) {
            return [];
        }
        if (!defined('TABLE_CATEGORIES') || !defined('TABLE_CATEGORIES_DESCRIPTION')) {
            return [];
        }

        $limit = max(5, min(80, $limit));
        $langId = 1;
        if (isset($_SESSION['languages_id']) && (int) $_SESSION['languages_id'] > 0) {
            $langId = (int) $_SESSION['languages_id'];
        } elseif (defined('DEFAULT_LANGUAGE')) {
            // keep 1 — categories_description is usually English id 1 on dig
        }

        $names = [];
        try {
            // Prefer root categories that have active children / products.
            $sql = 'SELECT cd.categories_name
                      FROM ' . TABLE_CATEGORIES . ' c
                      INNER JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd
                        ON cd.categories_id = c.categories_id
                       AND cd.language_id = ' . (int) $langId . '
                     WHERE c.categories_status = 1
                       AND c.parent_id = 0
                       AND cd.categories_name <> \'\'
                     ORDER BY c.sort_order ASC, cd.categories_name ASC
                     LIMIT ' . (int) $limit;
            $res = $db->Execute($sql);
            while ($res && !$res->EOF) {
                $name = trim((string) ($res->fields['categories_name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
                $res->MoveNext();
            }
        } catch (\Throwable $e) {
            return [];
        }

        // If the tree is deep (few roots), pull popular second-level names too.
        if (count($names) < 8) {
            try {
                $sql = 'SELECT cd.categories_name
                          FROM ' . TABLE_CATEGORIES . ' c
                          INNER JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd
                            ON cd.categories_id = c.categories_id
                           AND cd.language_id = ' . (int) $langId . '
                         WHERE c.categories_status = 1
                           AND c.parent_id > 0
                           AND cd.categories_name <> \'\'
                         ORDER BY c.sort_order ASC, cd.categories_name ASC
                         LIMIT ' . (int) $limit;
                $res = $db->Execute($sql);
                while ($res && !$res->EOF && count($names) < $limit) {
                    $name = trim((string) ($res->fields['categories_name'] ?? ''));
                    if ($name !== '' && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                    $res->MoveNext();
                }
            } catch (\Throwable $e) {
                // keep what we have
            }
        }

        return $names;
    }

    private static function asciiSafe(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Prefer transliteration when available; otherwise drop non-ASCII.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? $text;
        return trim($text);
    }
}

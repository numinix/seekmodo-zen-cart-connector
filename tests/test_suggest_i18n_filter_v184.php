<?php

declare(strict_types=1);

/**
 * Ticket #615048 — multilingual suggest locale filter (WP parity).
 *
 *     php tests/test_suggest_i18n_filter_v184.php
 */

$repoRoot = dirname(__DIR__);
$base = $repoRoot . DIRECTORY_SEPARATOR . 'zc_plugins' . DIRECTORY_SEPARATOR . 'Seekmodo';
$best = null;
$bestParts = [-1, -1, -1];
foreach (glob($base . DIRECTORY_SEPARATOR . 'v1.3.*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $name, $m)) {
        continue;
    }
    $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if ($parts > $bestParts) {
        $bestParts = $parts;
        $best = $dir;
    }
}

function i18n184_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

i18n184_assert(is_string($best) && is_dir($best), 'found a v1.3.x tree');
$ver = basename((string) $best);
i18n184_assert(
    version_compare(ltrim($ver, 'v'), '1.3.84', '>='),
    'latest tree is v1.3.84+, got ' . $ver
);

require_once $best . '/catalog/includes/functions/numinix_seekmodo_catalog_doc_lib.php';
require_once $best . '/catalog/includes/functions/numinix_seekmodo_search_lib.php';

if (!defined('TABLE_LANGUAGES')) {
    define('TABLE_LANGUAGES', 'languages');
}
if (!defined('DEFAULT_LANGUAGE')) {
    define('DEFAULT_LANGUAGE', 'english');
}

class I18n184FakeDbResult
{
    /** @var array<int, array<string, mixed>> */
    public array $rows;
    public bool $EOF = true;
    private int $idx = 0;
    /** @var array<string, mixed> */
    public array $fields = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->EOF = ($rows === []);
        if (!$this->EOF) {
            $this->fields = $rows[0];
        }
    }

    public function MoveNext(): void
    {
        $this->idx++;
        if ($this->idx >= count($this->rows)) {
            $this->EOF = true;
            $this->fields = [];
            return;
        }
        $this->fields = $this->rows[$this->idx];
        $this->EOF = false;
    }
}

class I18n184FakeDb
{
    /** @var array<string, I18n184FakeDbResult> */
    private array $results;

    /**
     * @param array<string, I18n184FakeDbResult> $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function Execute(string $sql): ?I18n184FakeDbResult
    {
        if (stripos($sql, 'COUNT(*)') !== false) {
            return $this->results['count'] ?? null;
        }
        if (stripos($sql, 'directory =') !== false) {
            return $this->results['default_id'] ?? null;
        }
        if (stripos($sql, 'SELECT languages_id, code, directory') !== false) {
            return $this->results['languages'] ?? null;
        }
        if (stripos($sql, 'languages_id =') !== false) {
            if (preg_match('/languages_id = (\d+)/', $sql, $m)) {
                $id = (int) $m[1];
                foreach ($this->results['languages']->rows ?? [] as $row) {
                    if ((int) ($row['languages_id'] ?? 0) === $id) {
                        return new I18n184FakeDbResult([$row]);
                    }
                }
            }
        }

        return null;
    }
}

$languageRows = [
    ['languages_id' => 1, 'code' => 'en', 'directory' => 'english'],
    ['languages_id' => 2, 'code' => 'de', 'directory' => 'german'],
    ['languages_id' => 3, 'code' => 'fr', 'directory' => 'french'],
    ['languages_id' => 4, 'code' => 'es', 'directory' => 'spanish'],
];

$GLOBALS['db'] = new I18n184FakeDb([
    'count' => new I18n184FakeDbResult([['c' => 4]]),
    'default_id' => new I18n184FakeDbResult([['languages_id' => 1]]),
    'languages' => new I18n184FakeDbResult($languageRows),
]);

$_SESSION['languages_id'] = 2;

i18n184_assert(numinix_seekmodo_storefront_is_multilingual(), 'storefront is multilingual');
$config = numinix_seekmodo_suggest_i18n_client_config();
i18n184_assert(is_array($config), 'i18n client config is array');
i18n184_assert(($config['slug'] ?? '') === 'de', 'active slug is de');
i18n184_assert(($config['prefixes']['de'] ?? '') === '/german/', 'de prefix is /german/');
i18n184_assert(($config['prefixes']['en'] ?? null) === '', 'default en prefix is empty');

$passthrough = _numinix_seekmodo_build_serp_passthrough();
i18n184_assert(
    isset($passthrough['filter_by']) && $passthrough['filter_by'] === 'lang:=de',
    'serp passthrough stamps lang filter'
);

$observer = (string) file_get_contents(
    $best . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php'
);
i18n184_assert(str_contains($observer, "'i18n_filter'"), 'observer wires i18n_filter');
i18n184_assert(str_contains($observer, '__seekmodoSuggestI18nFetch'), 'observer installs fetch filter');
i18n184_assert(str_contains($observer, 'mergeSerpPassthrough'), 'vehicle filter merges lang passthrough');

fwrite(STDOUT, "OK suggest i18n filter guards ({$ver})\n");

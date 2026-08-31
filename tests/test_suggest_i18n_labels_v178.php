<?php

declare(strict_types=1);

/**
 * Ticket #615048 — suggest i18n regression guards for the Zen Cart connector.
 *
 * Ensures language packs stay valid UTF-8 (no mojibake), German/French/Spanish
 * packs differ from English chrome, and the observer + vendored bundle still
 * wire `labels` / SeekmodoSuggestLabels into the widget.
 *
 *     php tests/test_suggest_i18n_labels_v178.php
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

function si18n_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

function si18n_load_pack(string $path): array
{
    si18n_assert(is_file($path), "pack exists: {$path}");
    $raw = file_get_contents($path);
    si18n_assert(is_string($raw) && $raw !== '', "pack readable: {$path}");
    // Reject UTF-8-as-Latin1-as-UTF-8 (Ã© / â€¦ style double encoding).
    si18n_assert(
        strpos($raw, "\xC3\x83\xC2") === false,
        "no double-encoded UTF-8 in {$path}"
    );
    si18n_assert(mb_check_encoding($raw, 'UTF-8'), "valid UTF-8: {$path}");
    /** @var array<string, string> $pack */
    $pack = require $path;
    si18n_assert(is_array($pack), "array return: {$path}");
    return $pack;
}

si18n_assert(is_string($best) && is_dir($best), 'found a v1.3.x tree');
$ver = basename((string) $best);
si18n_assert(
    version_compare(ltrim($ver, 'v'), '1.3.79', '>='),
    'latest tree is v1.3.79+, got ' . $ver
);

$langsRoot = $best . '/catalog/includes/languages';
$requiredKeys = [
    'TEXT_SEEKMODO_SUGGEST_RESULTS_FOR',
    'TEXT_SEEKMODO_SUGGEST_KEYWORDS',
    'TEXT_SEEKMODO_SUGGEST_PRICE_RANGE',
    'TEXT_SEEKMODO_SUGGEST_VIEW_ALL',
    'TEXT_SEEKMODO_SUGGEST_VIEW_ALL_SHORT',
    'TEXT_SEEKMODO_SUGGEST_PRODUCTS_COUNT',
    'TEXT_SEEKMODO_SUGGEST_PRODUCTS_PENDING',
    'TEXT_SEEKMODO_SUGGEST_POWERED_BY',
    'TEXT_SEEKMODO_SUGGEST_DID_YOU_MEAN',
    'TEXT_SEEKMODO_SUGGEST_BEST_MATCHES',
    'TEXT_SEEKMODO_SUGGEST_MORE_RESULTS',
    'TEXT_SEEKMODO_SUGGEST_TOP_MATCH',
    'TEXT_SEEKMODO_SUGGEST_CATEGORY_FILTER',
    'TEXT_SEEKMODO_SUGGEST_RELATED',
    'TEXT_SEEKMODO_SUGGEST_TRY',
    'TEXT_SEEKMODO_SUGGEST_PAGE',
    'TEXT_SEEKMODO_SUGGEST_ARTICLE',
    'TEXT_SEEKMODO_SUGGEST_TOOL',
    'TEXT_SEEKMODO_SUGGEST_TOOLS',
];

$en = si18n_load_pack($langsRoot . '/english/extra_definitions/lang.numinix_seekmodo.php');
$de = si18n_load_pack($langsRoot . '/german/extra_definitions/lang.numinix_seekmodo.php');
$fr = si18n_load_pack($langsRoot . '/french/extra_definitions/lang.numinix_seekmodo.php');
$es = si18n_load_pack($langsRoot . '/spanish/extra_definitions/lang.numinix_seekmodo.php');

foreach ([$en, $de, $fr, $es] as $pack) {
    foreach ($requiredKeys as $key) {
        si18n_assert(
            isset($pack[$key]) && is_string($pack[$key]) && $pack[$key] !== '',
            "pack has {$key}"
        );
    }
}

si18n_assert(
    str_contains($de['TEXT_SEEKMODO_SUGGEST_RESULTS_FOR'], 'Ergebnisse'),
    'German results_for uses Ergebnisse'
);
si18n_assert(
    !str_contains(strtolower($de['TEXT_SEEKMODO_SUGGEST_RESULTS_FOR']), 'results for'),
    'German results_for is not English'
);
si18n_assert(
    str_contains($fr['TEXT_SEEKMODO_SUGGEST_RESULTS_FOR'], 'résultats'),
    'French results_for uses résultats'
);
si18n_assert(
    str_contains($es['TEXT_SEEKMODO_SUGGEST_RESULTS_FOR'], 'resultados'),
    'Spanish results_for uses resultados'
);
si18n_assert(
    $de['TEXT_SEEKMODO_SUGGEST_KEYWORDS'] !== $en['TEXT_SEEKMODO_SUGGEST_KEYWORDS'],
    'German keywords differ from English'
);
si18n_assert(
    $fr['TEXT_SEEKMODO_SUGGEST_KEYWORDS'] !== $en['TEXT_SEEKMODO_SUGGEST_KEYWORDS'],
    'French keywords differ from English'
);
si18n_assert(
    $es['TEXT_SEEKMODO_SUGGEST_KEYWORDS'] !== $en['TEXT_SEEKMODO_SUGGEST_KEYWORDS'],
    'Spanish keywords differ from English'
);

// Arrow / ellipsis must be real Unicode, not mojibake of CP1252.
foreach (['german' => $de, 'french' => $fr, 'spanish' => $es, 'english' => $en] as $name => $pack) {
    si18n_assert(
        str_contains($pack['TEXT_SEEKMODO_SUGGEST_VIEW_ALL_SHORT'], "\u{2192}"),
        "{$name} view_all_short contains →"
    );
}

$observer = (string) file_get_contents(
    $best . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php'
);
si18n_assert($observer !== '', 'observer readable');
si18n_assert(
    str_contains($observer, "setAttribute('labels'")
        || str_contains($observer, 'setAttribute("labels"')
        || str_contains($observer, "el.setAttribute('labels'")
        || str_contains($observer, 'labels'),
    'observer wires labels'
);
si18n_assert(str_contains($observer, 'SeekmodoSuggestLabels'), 'observer sets SeekmodoSuggestLabels');
si18n_assert(str_contains($observer, 'function suggestLabels'), 'observer has suggestLabels()');
si18n_assert(
    str_contains($observer, "'results_for'")
        && str_contains($observer, 'TEXT_SEEKMODO_SUGGEST_RESULTS_FOR'),
    'suggestLabels maps results_for'
);

$bundle = (string) file_get_contents(
    $best . '/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js'
);
si18n_assert($bundle !== '', 'suggest bundle readable');
si18n_assert(str_contains($bundle, 'SeekmodoSuggestLabels'), 'bundle reads SeekmodoSuggestLabels');
si18n_assert(str_contains($bundle, 'results_for'), 'bundle has results_for label key');
// Guard against the pre-fix failure mode: meta bar concatenating English only.
si18n_assert(
    !preg_match('/\$\{total\}\s*results for /', $bundle),
    'bundle must not hard-code "${total} results for "'
);

// define()-style packs must also be clean UTF-8 (ZC 1.5.7 path).
foreach (['english', 'german', 'deutsch', 'french', 'spanish'] as $lang) {
    $definePath = $langsRoot . '/' . $lang . '/extra_definitions/numinix_seekmodo.php';
    si18n_assert(is_file($definePath), "define pack exists: {$lang}");
    $raw = (string) file_get_contents($definePath);
    si18n_assert(mb_check_encoding($raw, 'UTF-8'), "define pack UTF-8: {$lang}");
    si18n_assert(
        strpos($raw, "\xC3\x83\xC2") === false,
        "define pack not double-encoded: {$lang}"
    );
}

$deutsch = si18n_load_pack($langsRoot . '/deutsch/extra_definitions/lang.numinix_seekmodo.php');
si18n_assert(
    str_contains($deutsch['TEXT_SEEKMODO_SUGGEST_KEYWORDS'], 'Vorschläge'),
    'deutsch keywords are Vorschläge'
);

fwrite(STDOUT, "OK suggest i18n guards ({$ver})\n");

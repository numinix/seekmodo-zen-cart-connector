<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Loads compiled knowledge-base markdown files and kb-index.json.
 */
final class KbStore
{
    private string $kbPath;

    /** @var array<string, string>|null */
    private ?array $pages = null;

    /** @var array<string, mixed>|null */
    private ?array $index = null;

    public function __construct(?string $kbPath = null)
    {
        $this->kbPath = $kbPath ?? $this->defaultPath();
    }

    /**
     * Get KB path, creating it if needed.
     */
    public function ensurePath(): string
    {
        $path = $this->kbPath;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Load the KB index.
     *
     * @return array{title: string, slug: string, updated: string}[]
     */
    public function loadIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $indexFile = $this->ensurePath() . '/kb-index.json';
        if (!is_file($indexFile)) {
            $this->index = [];
            return $this->index;
        }
        $data = json_decode(file_get_contents($indexFile) ?: '', true);
        $this->index = is_array($data) ? $data : [];
        return $this->index;
    }

    /**
     * Save the KB index.
     *
     * @param list<array{title: string, slug: string, updated: string}> $entries
     */
    public function saveIndex(array $entries): void
    {
        $this->ensurePath();
        file_put_contents(
            $this->kbPath . '/kb-index.json',
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->index = $entries;
    }

    /**
     * Get the content of a KB page by slug.
     */
    public function getPage(string $slug): ?string
    {
        if ($this->pages !== null && isset($this->pages[$slug])) {
            return $this->pages[$slug];
        }
        $file = $this->ensurePath() . '/' . $slug . '.md';
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file) ?: null;
        if ($this->pages === null) {
            $this->pages = [];
        }
        if ($content !== null) {
            $this->pages[$slug] = $content;
        }
        return $content;
    }

    /**
     * Save a KB page.
     */
    public function savePage(string $slug, string $title, string $content): void
    {
        $this->ensurePath();
        $md = "# {$title}\n\n{$content}";
        file_put_contents($this->kbPath . '/' . $slug . '.md', $md);
    }

    /**
     * Search KB pages by keyword. Returns matching chunks (first ~2000 chars of each match).
     *
     * @return list<array{slug: string, title: string, snippet: string, score: float}>
     */
    public function search(string $query): array
    {
        $index = $this->loadIndex();
        $results = [];
        $terms = preg_split('/\s+/', mb_strtolower($query));
        $terms = array_filter($terms ?? []);

        foreach ($index as $entry) {
            $slug = $entry['slug'] ?? '';
            $title = $entry['title'] ?? '';
            $content = $this->getPage($slug);
            if ($content === null) {
                continue;
            }

            $contentLower = mb_strtolower($content);
            $score = 0.0;
            foreach ($terms as $term) {
                $count = mb_substr_count($contentLower, $term);
                if ($count > 0) {
                    $score += $count * 2;
                }
                $titleCount = mb_substr_count(mb_strtolower($title), $term);
                if ($titleCount > 0) {
                    $score += $titleCount * 5;
                }
            }
            if ($score > 0) {
                $snippet = mb_substr($content, 0, 2000);
                $results[] = [
                    'slug' => $slug,
                    'title' => $title,
                    'snippet' => $snippet,
                    'score' => $score,
                ];
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 5);
    }

    /**
     * Total page count.
     */
    public function count(): int
    {
        return count($this->loadIndex());
    }

    private function defaultPath(): string
    {
        $cache = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG . 'cache' : (defined('DIR_FS_LOGS') ? DIR_FS_LOGS : sys_get_temp_dir());
        return $cache . '/seekmodo_ai_chatbot/kb';
    }
}

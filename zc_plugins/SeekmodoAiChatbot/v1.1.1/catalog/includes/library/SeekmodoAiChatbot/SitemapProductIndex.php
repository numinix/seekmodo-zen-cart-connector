<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Parses Zen Cart product sitemap XML into a products_id → URL/index map.
 */
final class SitemapProductIndex
{
    private string $cachePath;

    /** @var array<int, array{products_id: int, url: string, name: string}>|null */
    private ?array $index = null;

    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = $cachePath ?? $this->defaultPath();
    }

    /**
     * Build the product index from sitemap XML files.
     *
     * Looks in the catalog root for sitemapproducts*.xml files.
     * Caches the result as sitemap-index.json beside the KB files.
     *
     * @return list<array{products_id: int, url: string, name: string}>
     */
    public function build(): array
    {
        $catalogDir = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : realpath(__DIR__ . '/../../../../');
        $sitemapFiles = glob($catalogDir . '/sitemapproducts*.xml');

        $products = [];

        foreach ($sitemapFiles as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->url as $url) {
                $loc = (string) $url->loc;
                $pid = $this->extractProductId($loc);
                if ($pid !== null) {
                    $name = $this->extractProductName($url);
                    $products[] = [
                        'products_id' => $pid,
                        'url' => $loc,
                        'name' => $name,
                    ];
                }
            }
        }

        $this->saveIndex($products);
        return $products;
    }

    /**
     * Search the product index by name keyword.
     *
     * @return list<array{products_id: int, url: string, name: string, score: float}>
     */
    public function search(string $query): array
    {
        $index = $this->loadIndex();
        $terms = preg_split('/\s+/', mb_strtolower($query));
        $terms = array_filter($terms ?? []);
        $results = [];

        foreach ($index as $product) {
            $nameLower = mb_strtolower($product['name']);
            $score = 0.0;
            foreach ($terms as $term) {
                if (mb_substr_count($nameLower, $term) > 0) {
                    $score += mb_substr_count($nameLower, $term) * 2;
                }
            }
            if ($score > 0) {
                $results[] = [
                    'products_id' => $product['products_id'],
                    'url' => $product['url'],
                    'name' => $product['name'],
                    'score' => $score,
                ];
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 10);
    }

    /**
     * Find a product by exact name match.
     */
    public function findByName(string $name): ?array
    {
        $index = $this->loadIndex();
        $nameLower = mb_strtolower(trim($name));
        foreach ($index as $product) {
            if (mb_strtolower($product['name']) === $nameLower) {
                return $product;
            }
        }
        return null;
    }

    /**
     * Get a product by ID.
     */
    public function findById(int $productsId): ?array
    {
        $index = $this->loadIndex();
        foreach ($index as $product) {
            if ($product['products_id'] === $productsId) {
                return $product;
            }
        }
        return null;
    }

    /**
     * Total product count.
     */
    public function count(): int
    {
        return count($this->loadIndex());
    }

    /**
     * @return list<array{products_id: int, url: string, name: string}>
     */
    private function loadIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $file = $this->cachePath . '/sitemap-index.json';
        if (!is_file($file)) {
            $this->index = [];
            return $this->index;
        }
        $data = json_decode(file_get_contents($file) ?: '', true);
        $this->index = is_array($data) ? $data : [];
        return $this->index;
    }

    /**
     * @param list<array{products_id: int, url: string, name: string}> $index
     */
    private function saveIndex(array $index): void
    {
        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0755, true);
        }
        file_put_contents(
            $this->cachePath . '/sitemap-index.json',
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->index = $index;
    }

    private function extractProductId(string $url): ?int
    {
        if (preg_match('/[?&]products_id=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * @param \SimpleXMLElement $url
     */
    private function extractProductName($url): string
    {
        $ns = $url->getNamespaces(true);
        if (isset($ns[''])) {
            $url->registerXPathNamespace('sitemap', $ns['']);
            $image = $url->xpath('sitemap:image:sitemap:image:sitemap:caption');
            if ($image && isset($image[0])) {
                return (string) $image[0];
            }
        }
        return '';
    }

    private function defaultPath(): string
    {
        $cache = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG . 'cache' : (defined('DIR_FS_LOGS') ? DIR_FS_LOGS : sys_get_temp_dir());
        return $cache . '/seekmodo_ai_chatbot/kb';
    }
}

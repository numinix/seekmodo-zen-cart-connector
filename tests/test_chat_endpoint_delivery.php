<?php
declare(strict_types=1);

require __DIR__ . '/../zc_plugins/SeekmodoAiChatbot/v1.1.1/catalog/includes/library/SeekmodoAiChatbot/CatalogEndpoints.php';
use SeekmodoAiChatbot\CatalogEndpoints;

$fixture = sys_get_temp_dir() . '/seekmodo-chat-delivery-' . bin2hex(random_bytes(6));
mkdir($fixture . '/root', 0755, true);
mkdir($fixture . '/plugin', 0755, true);
try {
    foreach (CatalogEndpoints::FILES as $file) {
        file_put_contents($fixture . '/plugin/' . $file, '<?php echo ' . var_export($file, true) . ';');
    }
    foreach ([1, 2] as $pass) {
        if ($pass === 2) {
            foreach (CatalogEndpoints::FILES as $file) {
                unlink($fixture . '/root/' . $file);
            }
        }
        if (CatalogEndpoints::ensure($fixture . '/root', $fixture . '/plugin') !== []) {
            throw new RuntimeException('Endpoint repair failed');
        }
        foreach (CatalogEndpoints::FILES as $file) {
            if (file_get_contents($fixture . '/root/' . $file) !== file_get_contents($fixture . '/plugin/' . $file)) {
                throw new RuntimeException('Missing or incomplete endpoint after deploy simulation');
            }
        }
    }
    $custom = $fixture . '/root/' . CatalogEndpoints::FILES[0];
    file_put_contents($custom, '<?php echo "tracked loader";');
    CatalogEndpoints::ensure($fixture . '/root', $fixture . '/plugin');
    if (file_get_contents($custom) !== '<?php echo "tracked loader";') {
        throw new RuntimeException('Do not overwrite deploy-tracked loaders');
    }
    unlink($fixture . '/root/' . CatalogEndpoints::FILES[1]);
    unlink($fixture . '/plugin/' . CatalogEndpoints::FILES[1]);
    if (CatalogEndpoints::ensure($fixture . '/root', $fixture . '/plugin') !== [CatalogEndpoints::FILES[1]]) {
        throw new RuntimeException('Missing source must be reported');
    }
    $init = file_get_contents(__DIR__ . '/../zc_plugins/SeekmodoAiChatbot/v1.1.1/catalog/includes/init_includes/init_seekmodo_ai_chatbot.php');
    if (strpos($init, 'CatalogEndpoints::ensure') === false) {
        throw new RuntimeException('Storefront startup must repair public endpoints');
    }
    echo "PASS: endpoint creation, deploy-deletion recovery, loader preservation, failure reporting\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($fixture);
}

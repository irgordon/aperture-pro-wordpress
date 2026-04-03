<?php

namespace ZipStream\Option {
    if (!class_exists('ZipStream\Option\Archive')) {
        class Archive {
            public function setSendHttpHeaders($v) {}
            public function setContentType($v) {}
            public function setEnableZip64($v) {}
        }
    }
}

namespace ZipStream {
    if (!class_exists('ZipStream\ZipStream')) {
        class ZipStream {
            public $filesAdded = [];
            public function __construct($name, $options) {}
            public function addFileFromStream($name, $stream) {
                $this->filesAdded[] = $name;
            }
            public function finish() {}
        }
    }
}

namespace {
    // Mock environment
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/../');
    if (!defined('APERTURE_PRO_FILE')) define('APERTURE_PRO_FILE', __DIR__ . '/../aperture-pro.php');

    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value) { return $value; }
    }
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) { return tempnam(sys_get_temp_dir(), $prefix); }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return false; }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args) { return ['response' => ['code' => 200]]; }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
    }

    // Autoload
    require_once __DIR__ . '/../src/Helpers/Logger.php';
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Download/ZipStreamService.php';

    // Mock dependencies
    if (!class_exists('StorageMock')) {
        class StorageMock implements \AperturePro\Storage\StorageInterface {
            public function getName(): string { return 'Mock'; }
            public function upload(string $src, string $tgt, array $opt = []): string { return ''; }
            public function uploadMany(array $files): array { return []; }
            public function delete(string $tgt): void {}
            public function getUrl(string $tgt, array $opt = []): string { return 'http://example.com/' . $tgt; }
            public function exists(string $tgt): bool { return true; }
            public function existsMany(array $tgts): array {
                $res = [];
                foreach ($tgts as $t) $res[$t] = true;
                return $res;
            }
            public function sign(string $path): ?string { return 'signed://' . $path; }
            public function signMany(array $paths): array {
                $res = [];
                foreach ($paths as $p) $res[$p] = 'signed://' . $p;
                return $res;
            }
            public function getStats(): array { return []; }
        }
    }

    use AperturePro\Download\ZipStreamService;

    echo "Running ZipStreamService validation..." . PHP_EOL;

    $files = [
        ['path' => 'file1.jpg', 'name' => 'First.jpg'],
        ['path' => 'file2.jpg', 'name' => 'Second.jpg'],
    ];

    $storage = new StorageMock();

    try {
        ZipStreamService::streamZip($files, $storage, 'test.zip');
        echo "ZipStreamService::streamZip executed successfully (Logical validation)." . PHP_EOL;
    } catch (\Throwable $e) {
        echo "Caught: " . $e->getMessage() . PHP_EOL;
    }
}

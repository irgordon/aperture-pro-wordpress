<?php

namespace {
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix = '') {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
    }

    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            return ['basedir' => sys_get_temp_dir(), 'baseurl' => 'http://localhost'];
        }
    }

    if (!function_exists('trailingslashit')) {
        function trailingslashit($s) { return rtrim($s, '/') . '/'; }
    }

    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p($path) { return true; }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($t) { return false; }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value) { return $value; }
    }

    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth') { return 'salt'; }
    }
}

namespace AperturePro\Proof {
    function extension_loaded($ext) {
        // Force fallback to avoid GD/Imagick overhead
        if ($ext === 'imagick' || $ext === 'gd') {
            return false;
        }
        return \extension_loaded($ext);
    }
}

namespace AperturePro\Benchmark {

    require_once __DIR__ . '/../src/Proof/ProofService.php';
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Storage/Traits/Retryable.php';
    require_once __DIR__ . '/../src/Storage/AbstractStorage.php';
    require_once __DIR__ . '/../src/Storage/LocalStorage.php';

    use AperturePro\Proof\ProofService;
    use AperturePro\Storage\LocalStorage;

    // Mock Config
    if (!class_exists('AperturePro\Config\Config')) {
        class MockConfig {
            public static function get($key, $default = null) {
                return $default;
            }
        }
        class_alias(MockConfig::class, 'AperturePro\Config\Config');
    }

    // Mock Logger
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class MockLogger {
            public static function log($level, $type, $message, $context = []) {
                // Silent
            }
        }
        class_alias(MockLogger::class, 'AperturePro\Helpers\Logger');
    }

    // Mock Storage with latency
    class BenchmarkStorage extends LocalStorage {
        public function __construct() {
            parent::__construct(['path' => 'benchmark-storage']);
            // Create a dummy file to serve as "original"
            $this->dummyOriginal = sys_get_temp_dir() . '/dummy_original.jpg';
            file_put_contents($this->dummyOriginal, 'fake_image_content');
        }

        public function getLocalPath(string $target): ?string {
            // Return valid local path to bypass download
            return $this->dummyOriginal;
        }

        public function upload(string $source, string $target, array $options = []): string {
            // Simulate network latency
            usleep(100000); // 100ms
            return 'http://mock/url/' . $target;
        }

        public function uploadMany(array $files): array {
            // Simulate parallel upload (faster than sequential)
            // Let's say it takes 150ms total regardless of count (or slightly scaling)
            usleep(150000); // 150ms

            $results = [];
            foreach ($files as $file) {
                $results[$file['target']] = [
                    'success' => true,
                    'url' => 'http://mock/url/' . $file['target'],
                    'error' => null
                ];
            }
            return $results;
        }
    }

    // Run Benchmark
    echo "--------------------------------------------------\n";
    echo "Benchmark: Proof Generation Upload Strategy\n";
    echo "--------------------------------------------------\n";

    $storage = new BenchmarkStorage();
    $itemCount = 10;
    $items = [];

    for ($i = 0; $i < $itemCount; $i++) {
        $items[] = [
            'original_path' => "original_$i.jpg",
            'proof_path'    => "proof_$i.jpg",
        ];
    }

    echo "Processing $itemCount items...\n";
    echo "Expected Baseline (Sequential): ~" . ($itemCount * 0.1) . "s + overhead\n";
    echo "Expected Optimized (Parallel):  ~0.15s + overhead\n\n";

    $start = microtime(true);

    $results = ProofService::generateBatch($items, $storage);

    $end = microtime(true);
    $duration = $end - $start;

    echo "Duration: " . number_format($duration, 4) . " seconds\n";
    echo "Items Processed: " . count($results) . "\n";

    // Verify results
    $successCount = 0;
    foreach ($results as $r) {
        if ($r === true) $successCount++;
    }
    echo "Success Count: $successCount\n";
}

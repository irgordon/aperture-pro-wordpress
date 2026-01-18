<?php
/**
 * Benchmark/Verification for Download Optimization
 * Usage: php tests/benchmark_download_optimization.php
 */

namespace AperturePro\Proof {
    // Mock file_get_contents to track usage
    function file_get_contents($filename) {
        global $use_mock_file_get_contents;
        if (isset($use_mock_file_get_contents) && $use_mock_file_get_contents && strpos($filename, 'http') === 0) {
            echo "[Mock] file_get_contents called for: $filename\n";
            return "fake_image_content";
        }
        return \file_get_contents($filename);
    }
}

namespace {

    $use_mock_file_get_contents = true;
    $mock_wp_remote_get_called = false;

    // Mock WordPress functions
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix = '') {
            $tmp = tempnam(sys_get_temp_dir(), $prefix);
            return $tmp;
        }
    }

    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = []) {
            global $mock_wp_remote_get_called;
            $mock_wp_remote_get_called = true;
            echo "[Mock] wp_remote_get called for: $url\n";
            if (isset($args['stream']) && $args['stream'] && isset($args['filename'])) {
                echo "[Mock] Streaming to: " . $args['filename'] . "\n";
                file_put_contents($args['filename'], "fake_image_content_streamed");
            }
            return [
                'response' => ['code' => 200],
                'body' => "fake_image_content_streamed"
            ];
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            return false;
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) {
            return $response['response']['code'] ?? 500;
        }
    }

    // Mock Logger
    class MockLogger {
        public static function log($level, $context, $message, $data = []) {
            echo "[Log][$level] $message\n";
        }
    }
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class_alias('MockLogger', 'AperturePro\Helpers\Logger');
    }

    // Require source files
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Storage\StorageInterface;
    use AperturePro\Proof\ProofService;

    // Mock Storage
    class MockStorage implements StorageInterface
    {
        public function getName(): string { return 'MockStorage'; }
        public function upload(string $source, string $target, array $options = []): string { return 'url'; }
        public function delete(string $target): void {}
        public function getUrl(string $target, array $options = []): string { return "http://example.com/$target"; }
        public function exists(string $target): bool { return false; }
        public function getStats(): array { return []; }
        public function existsMany(array $targets): array { return []; }
    }

    // Helper to reflectively call protected method
    function call_downloadToTemp($remotePath, $storage) {
        $reflection = new \ReflectionClass(ProofService::class);
        $method = $reflection->getMethod('downloadToTemp');
        $method->setAccessible(true);
        return $method->invoke(null, $remotePath, $storage);
    }

    echo "--- Starting Download Benchmark/Verification ---\n";

    $storage = new MockStorage();

    $start = microtime(true);
    $tmpFile = call_downloadToTemp('projects/1/test.jpg', $storage);
    $end = microtime(true);

    if ($tmpFile && file_exists($tmpFile)) {
        $content = file_get_contents($tmpFile);
        echo "Download successful. Content: $content\n";
        @unlink($tmpFile);
    } else {
        echo "Download failed.\n";
    }

    echo "Time taken: " . number_format($end - $start, 4) . "s\n";

    global $mock_wp_remote_get_called;
    if ($mock_wp_remote_get_called) {
        echo "RESULT: wp_remote_get was used.\n";
    } else {
        echo "RESULT: wp_remote_get was NOT used.\n";
    }
}

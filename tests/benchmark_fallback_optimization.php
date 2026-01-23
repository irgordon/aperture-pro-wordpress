<?php
/**
 * Benchmark: Parallel Downloads Fallback Optimization
 * Usage: php tests/benchmark_fallback_optimization.php
 */

// Mock Namespace to disable curl_multi_init
namespace AperturePro\Proof {
    function function_exists($func) {
        if ($func === 'curl_multi_init') return false;
        return \function_exists($func);
    }
}

namespace {
    // Global mocks
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = []) {
            // Minimal mock for wp_remote_get
            return ['response' => ['code' => 200]];
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return false; }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) { return 200; }
    }
}

namespace AperturePro\Test {
    require_once __DIR__ . '/../src/Proof/ProofService.php';
    require_once __DIR__ . '/../src/Helpers/Logger.php';
    require_once __DIR__ . '/../src/Proof/ProofCache.php';

    // Mock Logger
    class MockLogger {
        public static function log($level, $context, $message, $data = []) {
             // echo "LOG: $message " . json_encode($data) . "\n";
        }
    }
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class_alias('AperturePro\Test\MockLogger', 'AperturePro\Helpers\Logger');
    }

    use AperturePro\Proof\ProofService;

    $delay = 1;
    $count = 3;
    $url = "https://httpbin.org/delay/$delay";
    $urls = array_fill(0, $count, $url);

    echo "Benchmarking $count requests to $url...\n";
    echo "(Simulating NO curl_multi_init)\n\n";

    // Access protected method via reflection
    $method = new \ReflectionMethod(ProofService::class, 'performParallelDownloads');
    $method->setAccessible(true);

    $start = microtime(true);
    $results = $method->invoke(null, $urls);
    $time = microtime(true) - $start;

    echo "Time: " . number_format($time, 4) . "s\n";
    echo "Expected (Sequential): ~" . ($count * $delay) . "s\n";
    echo "Expected (Parallel):   ~" . ($delay) . "s\n";

    foreach ($results as $path) {
        if (file_exists($path)) @unlink($path);
    }
}

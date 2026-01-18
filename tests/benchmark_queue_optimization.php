<?php
/**
 * Benchmark for Proof Queue Batch Optimization
 * Usage: php tests/benchmark_queue_optimization.php
 */

require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/StorageFactory.php';
require_once __DIR__ . '/../src/Proof/ProofCache.php';
require_once __DIR__ . '/../src/Proof/ProofQueue.php';
require_once __DIR__ . '/../src/Proof/ProofService.php';

// Mock Dependencies
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) { return true; }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; }
}

// Global mock storage for options to simulate DB
global $mock_options;
$mock_options = [];

// Mock Transients for ProofCache
$mock_transients = [];
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        // Simulate DB Read Latency
        usleep(1000); // 1ms
        return $mock_options[$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        // Simulate DB Write Latency
        usleep(5000); // 5ms
        $mock_options[$option] = $value;
        return true;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        // Simulate DB Write for cron
        usleep(2000); // 2ms
        return true;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        // Simulate DB Read for cron check
        usleep(1000); // 1ms
        return false;
    }
}
function current_time($type) { return date('Y-m-d H:i:s'); }

class MockLogger {
    public static function log($level, $context, $message, $data = []) {}
}
if (!class_exists('AperturePro\Helpers\Logger')) {
    class_alias('MockLogger', 'AperturePro\Helpers\Logger');
}

use AperturePro\Storage\StorageInterface;
use AperturePro\Proof\ProofService;

// Mock Storage that simulates MISSING proofs
class MockMissingStorage implements StorageInterface
{
    public function getName(): string { return 'MockMissing'; }
    public function upload(string $source, string $target, array $options = []): string { return 'url'; }
    public function delete(string $target): void {}
    public function getUrl(string $target, array $options = []): string { return "http://cdn.example.com/$target"; }
    public function exists(string $target): bool { return false; }
    public function getStats(): array { return []; }
    public function existsMany(array $targets): array {
        $results = [];
        foreach ($targets as $target) {
            $results[$target] = false;
        }
        return $results;
    }
}

// Setup Data - Use a larger number to make the difference obvious
$count = 100;
$images = [];
for ($i = 0; $i < $count; $i++) {
    $images[] = [
        'id' => $i,
        'filename' => "image-$i.jpg",
        'path' => "projects/1/image-$i.jpg"
    ];
}

$storage = new MockMissingStorage();

echo "Starting Benchmark ($count images, all missing)...\n";
echo "Simulating 1ms read / 5ms write latency per DB call.\n";

$start = microtime(true);

// This will trigger queueing for all images
$proofUrlsMap = ProofService::getProofUrls($images, $storage);

$end = microtime(true);
$duration = $end - $start;

echo sprintf("Duration: %.4f seconds\n", $duration);
echo sprintf("Average per image: %.4f seconds\n", $duration / $count);

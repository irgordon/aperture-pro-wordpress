<?php

// Mock WordPress functions
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') {
        return tempnam(sys_get_temp_dir(), $prefix);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return 200;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

// Mock Options & Transients
$mock_options = [];
$mock_transients = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
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

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true;
    }
}

// Mock Logger
namespace AperturePro\Helpers;
class Logger {
    public static function log($level, $context, $message, $data = []) {
        // echo "[$level] $message\n";
    }
}

namespace AperturePro\Storage;
interface StorageInterface {
    public function getUrl(string $path, array $options = []): string;
    public function upload(string $source, string $destination, array $options = []): array;
    public function exists(string $path): bool;
    public function existsMany(array $paths): array;
    public function signMany(array $paths): array; // Ensure interface matches usage
    public function getLocalPath(string $path): ?string;
}

class MockStorage implements StorageInterface {
    public function getUrl(string $path, array $options = []): string {
        return "http://example.com/$path";
    }
    public function upload(string $source, string $destination, array $options = []): array {
        return ['url' => "http://example.com/$destination"];
    }
    public function exists(string $path): bool {
        // For benchmark, assume proof exists if it contains 'proof'
        return strpos($path, 'proof') !== false;
    }
    public function existsMany(array $paths): array {
        $results = [];
        foreach ($paths as $p) {
            $results[$p] = (strpos($p, 'proof') !== false);
        }
        return $results;
    }
    public function signMany(array $paths): array {
        $results = [];
        foreach ($paths as $p) {
            $results[$p] = "http://example.com/signed/$p?token=123";
        }
        return $results;
    }
    public function getLocalPath(string $path): ?string { return null; }
}

// Mock wp_remote_get for the baseline
// We simulate network latency
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        // Simulate 0.5s latency per request
        usleep(500000);

        // Write some dummy data to the file if stream is set
        if (!empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents($args['filename'], 'fake image data');
        }

        return ['response' => ['code' => 200]];
    }
}

// Load the classes under test
require_once __DIR__ . '/../src/Proof/ProofCache.php';
require_once __DIR__ . '/../src/Proof/ProofQueue.php';
require_once __DIR__ . '/../src/Proof/ProofService.php';

use AperturePro\Proof\ProofService;

// Benchmark 1: Proof Generation (Sequential)
$start = microtime(true);
$storage = new MockStorage();
$images = [
    'img1.jpg',
    'img2.jpg',
    'img3.jpg',
    'img4.jpg',
    'img5.jpg'
];

echo "1. Starting sequential processing of " . count($images) . " images...\n";

foreach ($images as $img) {
    $proofPath = "proofs/$img";
    ProofService::generateProofVariant($img, $proofPath, $storage);
}

$end = microtime(true);
$duration = $end - $start;
echo "   Duration: " . number_format($duration, 4) . " seconds\n\n";


// Benchmark 2: Optimized getProofUrls
echo "2. Testing Optimized ProofService::getProofUrls...\n";

// Generate 50 fake image records
$batchImages = [];
for ($i = 0; $i < 50; $i++) {
    $batchImages[] = [
        'id'   => $i + 100,
        'path' => "projects/123/img_{$i}.jpg",
    ];
}

// Mock storage where proofs "exist" for these
// (Our MockStorage assumes anything with 'proof' in path exists)

// Cold Cache Run
$start = microtime(true);
$urls = ProofService::getProofUrls($batchImages, $storage);
$coldTime = microtime(true) - $start;

echo "   Cold Cache Duration: " . number_format($coldTime, 6) . "s\n";
echo "   Count: " . count($urls) . "\n";

// Warm Cache Run (Should hit static request cache or transient cache)
$start = microtime(true);
$urlsCached = ProofService::getProofUrls($batchImages, $storage);
$warmTime = microtime(true) - $start;

echo "   Warm Cache Duration: " . number_format($warmTime, 6) . "s\n";
echo "   Count: " . count($urlsCached) . "\n";

if ($warmTime < $coldTime) {
    echo "   SUCCESS: Warm cache is faster.\n";
} else {
    echo "   WARNING: Warm cache not faster (could be negligible overhead).\n";
}

// Validate result count
if (count($urlsCached) !== 50) {
    echo "ERROR: Warm cache returned " . count($urlsCached) . " items\n";
}

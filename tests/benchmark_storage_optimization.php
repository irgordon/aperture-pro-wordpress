<?php

// Mock WordPress functions in global namespace
namespace {
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
}

// Mock Logger
namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // echo "[$level] $message\n";
        }
    }
}

namespace AperturePro\Storage {
    interface StorageInterface {
        public function getUrl(string $path, array $options = []): string;
        public function upload(string $source, string $destination, array $options = []): array;
        public function exists(string $path): bool;
        public function existsMany(array $paths): array;
        public function signMany(array $paths): array;
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
            return true;
        }
        public function existsMany(array $paths): array {
            // Simulate network latency (200ms)
            usleep(200000);

            $results = [];
            foreach ($paths as $p) {
                $results[$p] = true;
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
}

// Mock Config
namespace AperturePro\Config {
    class Config {
        public static function get($key, $default = null) {
            return $default;
        }
    }
}

// Main execution
namespace {
    // Mock $wpdb
    class MockWPDB {
        public $prefix = 'wp_';
        public function prepare($query, ...$args) { return $query; }
        public function get_var($query) { return null; }
        public function query($query) { return true; }
        public function esc_like($s) { return $s; }
    }
    global $wpdb;
    $wpdb = new MockWPDB();

    // Load the classes under test
    require_once __DIR__ . '/../src/Proof/ProofCache.php';
    require_once __DIR__ . '/../src/Proof/ProofQueue.php';
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Proof\ProofService;
    use AperturePro\Storage\MockStorage;
    use AperturePro\Proof\ProofCache;

    // Setup
    $storage = new MockStorage();
    $numImages = 20;

    echo "Benchmarking ProofService::getProofUrls with $numImages images...\n";
    echo "Storage latency simulated at 200ms per batch check.\n\n";

    // --- Run 1: Legacy Data (No has_proof) ---
    // This should trigger existsMany (slow) AND Lazy Migration
    $legacyImages = [];
    for ($i = 0; $i < $numImages; $i++) {
        $legacyImages[] = [
            'id'          => $i + 1,
            'project_id'  => 123,
            'path'        => "projects/123/img_{$i}.jpg",
            // has_proof missing
        ];
    }

    // Clear static cache in ProofService if possible, or use fresh call?
    // Use reflection to clear static $requestCache if needed, or just pass new image refs
    // But ProofService uses ProofCache::generateKey which hashes the array.
    // Changing the array content changes the key.

    $start = microtime(true);
    $urlsLegacy = ProofService::getProofUrls($legacyImages, $storage);
    $durationLegacy = microtime(true) - $start;

    echo "1. Legacy Data (No has_proof): " . number_format($durationLegacy, 4) . " s\n";
    if ($durationLegacy >= 0.2) {
         echo "   [PASS] Slow path taken (Lazy Migration triggered)\n";
    } else {
         echo "   [FAIL] Fast path taken? Should have been slow.\n";
    }

    // --- Run 2: Optimized Data (has_proof = 1) ---
    $optimizedImages = [];
    for ($i = 0; $i < $numImages; $i++) {
        $optimizedImages[] = [
            'id'          => $i + 1,
            'project_id'  => 123,
            'path'        => "projects/123/img_{$i}.jpg",
            'has_proof'   => 1, // Flagged!
        ];
    }

    $start = microtime(true);
    $urlsOpt = ProofService::getProofUrls($optimizedImages, $storage);
    $durationOpt = microtime(true) - $start;

    echo "2. Optimized Data (has_proof=1): " . number_format($durationOpt, 4) . " s\n";
    if ($durationOpt < 0.1) {
         echo "   [PASS] Fast path taken (Skipped existsMany)\n";
    } else {
         echo "   [FAIL] Slow path taken?\n";
    }

    // --- Run 3: Warm Cache ---
    $start = microtime(true);
    $urlsCached = ProofService::getProofUrls($optimizedImages, $storage);
    $durationCached = microtime(true) - $start;

    echo "3. Warm Cache: " . number_format($durationCached, 6) . " s\n";
}

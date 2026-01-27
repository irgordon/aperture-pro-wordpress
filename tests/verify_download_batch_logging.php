<?php

/**
 * Verification Test: ProofService::downloadBatchToTemp Logging
 *
 * GOAL:
 *  - Verify that failures in batch download are aggregated into a single log entry.
 */

namespace {
    // 1. Mock WordPress Environment
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../');
    }

    if (!defined('APERTURE_PRO_FILE')) {
        define('APERTURE_PRO_FILE', __FILE__);
    }

    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) { return sys_get_temp_dir() . '/' . uniqid($prefix); }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) { return json_encode($data); }
    }
    if (!function_exists('current_time')) {
        function current_time($type) { return date('Y-m-d H:i:s'); }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return false; }
    }

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        public $last_insert = [];

        public function insert($table, $data, $format) {
            $this->last_insert = $data;
            return 1;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();

    // Autoloader
    spl_autoload_register(function ($class) {
        if (strpos($class, 'AperturePro\\') === 0) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
            if (file_exists($file)) {
                echo "Loading: $file\n";
                require_once $file;
            } else {
                echo "File not found: $file\n";
            }
        }
    });
}

namespace AperturePro\Storage {
    interface StorageInterface {
        public function getUrl(string $path, array $options = []): string;
        public function getLocalPath(string $path): ?string;
    }

    class LocalStorage implements StorageInterface {
         public function getUrl(string $path, array $options = []): string { throw new \Exception("Fail"); }
         public function getLocalPath(string $path): ?string { return null; }
    }

    class StorageFactory {
        public static function create() { return new MockStorage(); }
    }

    class MockStorage implements StorageInterface {
        public function getUrl(string $path, array $options = []): string {
            throw new \Exception("Simulated Storage Failure for $path");
        }
        public function getLocalPath(string $path): ?string {
            return null;
        }
    }
}

namespace {
    use AperturePro\Proof\ProofService;
    use AperturePro\Storage\MockStorage;

    // Use Reflection to access protected method
    $method = new ReflectionMethod(ProofService::class, 'downloadBatchToTemp');
    $method->setAccessible(true);

    // Prepare batch
    $count = 50;
    $paths = [];
    for ($i = 0; $i < $count; $i++) {
        $paths[] = "projects/1/image_{$i}.jpg";
    }

    $storage = new MockStorage();

    echo "Running Verification: ProofService::downloadBatchToTemp Log Aggregation...\n";

    global $wpdb;
    // Clear previous state
    $wpdb->last_insert = [];

    // Invoke the protected method
    $results = $method->invoke(null, $paths, $storage);

    // Verify Log Entry
    $logData = $wpdb->last_insert;

    if (empty($logData)) {
        echo "FAILED: No log entry created.\n";
        exit(1);
    }

    if ($logData['message'] !== 'Failed to get URLs for batch items') {
        echo "FAILED: Incorrect log message. Got: " . $logData['message'] . "\n";
        exit(1);
    }

    $meta = json_decode($logData['meta'], true);
    if ($meta['count'] !== $count) {
        echo "FAILED: Incorrect count in meta. Expected $count, got " . $meta['count'] . "\n";
        exit(1);
    }

    echo "PASSED: Log aggregation verified successfully.\n";
}

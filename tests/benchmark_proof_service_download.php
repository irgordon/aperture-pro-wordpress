<?php

/**
 * Benchmark: ProofService::downloadBatchToTemp N+1 Logging
 *
 * GOAL:
 *  - Measure the number of database inserts (Logger::log calls) when storage getUrl fails for a batch of images.
 */

namespace {
    // 1. Mock WordPress Environment
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../');
    }

    // Mock Config
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

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        public $insert_count = 0;

        public function insert($table, $data, $format) {
            $this->insert_count++;
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
                require_once $file;
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

    // Mock Storage Factory to not need it, but included for completeness
    class StorageFactory {
        public static function create() { return new MockStorage(); }
    }

    // Actual mock we will use
    class MockStorage implements StorageInterface {
        public function getUrl(string $path, array $options = []): string {
            throw new \Exception("Simulated Storage Failure for $path");
        }
        public function getLocalPath(string $path): ?string {
            return null; // Force HTTP path
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
    $count = 100;
    $paths = [];
    for ($i = 0; $i < $count; $i++) {
        $paths[] = "projects/1/image_{$i}.jpg";
    }

    $storage = new MockStorage();

    echo "Starting Benchmark: ProofService::downloadBatchToTemp (N+1 Logging)...\n";

    global $wpdb;
    $wpdb->insert_count = 0;

    $start = microtime(true);

    // Invoke the protected method
    $results = $method->invoke(null, $paths, $storage);

    $end = microtime(true);
    $duration = $end - $start;
    $queries = $wpdb->insert_count;

    echo "Processed $count failing items.\n";
    echo "Time: " . number_format($duration, 4) . "s\n";
    echo "DB Inserts (Logger calls): " . $queries . "\n";

    if ($queries >= $count) {
        echo "BASELINE CONFIRMED: N+1 issue present.\n";
    } else {
        echo "WARNING: N+1 issue NOT reproduced (Queries: $queries).\n";
    }
}

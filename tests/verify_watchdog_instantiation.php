<?php
/**
 * Verification Script for Watchdog Optimization
 */

namespace {
    // Global variable for test dir
    $test_upload_dir = sys_get_temp_dir() . '/ap_watchdog_test_' . uniqid();

    // Mock WP Functions
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            global $test_upload_dir;
            return ['basedir' => $test_upload_dir, 'baseurl' => 'http://localhost'];
        }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit($path) { return rtrim($path, '/') . '/'; }
    }
    if (!function_exists('current_time')) {
        function current_time($type) { return date('Y-m-d H:i:s'); }
    }
    if (!function_exists('get_transient')) {
        function get_transient($key) { return false; } // Always return empty so it thinks it's stale/orphaned
    }
    if (!function_exists('set_transient')) {
        function set_transient($key, $val, $ttl) { return true; }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient($key) { return true; }
    }
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $meta = []) {}
    }
}

namespace AperturePro\Email {
    class EmailService {
        public static function enqueueAdminNotification($level, $context, $subject, $meta) {}
    }
}

namespace AperturePro\Upload {
    class ChunkedUploadHandler {
        const SESSION_TRANSIENT_PREFIX = 'ap_upload_';
        const ASSEMBLED_FILENAME = 'assembled.file';
        public static function cleanupSessionFiles($dir) {
            // Simple recursive delete for test
            $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        }
    }
}

namespace AperturePro\Storage {
    class MockStorage {
        public function upload($source, $target, $options = []) {
            return ['success' => true];
        }
    }

    class StorageFactory {
        public static $makeCalls = 0;
        public static function make() {
            self::$makeCalls++;
            return new MockStorage();
        }
    }
}

namespace AperturePro\Tests {
    use AperturePro\Upload\Watchdog;
    use AperturePro\Storage\StorageFactory;

    // Load Watchdog
    require_once __DIR__ . '/../src/Upload/Watchdog.php';

    // Setup Test Environment
    global $test_upload_dir;
    if (!file_exists($test_upload_dir)) mkdir($test_upload_dir);
    $apUploads = $test_upload_dir . '/aperture-uploads';
    if (!file_exists($apUploads)) mkdir($apUploads);

    // Create 3 fake orphaned upload directories
    for ($i = 1; $i <= 3; $i++) {
        $dir = $apUploads . '/upload_' . $i;
        mkdir($dir);
        // Create assembled file
        file_put_contents($dir . '/assembled.file', 'dummy content');
    }

    echo "Running Watchdog...\n";
    Watchdog::run();

    echo "StorageFactory::make() called " . StorageFactory::$makeCalls . " times.\n";

    if (StorageFactory::$makeCalls === 1) {
        echo "[PASS] StorageFactory instantiated exactly once.\n";
    } else {
        echo "[FAIL] StorageFactory instantiated " . StorageFactory::$makeCalls . " times (expected 1).\n";
        exit(1);
    }

    // Cleanup
    // (Optional, system temp cleans up eventually, but good practice)
    if (is_dir($test_upload_dir)) {
        // ... cleanup logic
    }
}

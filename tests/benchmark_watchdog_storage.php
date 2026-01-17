<?php

/**
 * Benchmark: Watchdog Storage Instantiation
 *
 * Simulates the performance impact of moving StorageFactory::make() out of the loop.
 */

namespace {
    // Mock WP Constants in Global Scope
    if (!defined('AUTH_KEY')) define('AUTH_KEY', 'test_auth_key');
    if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', 'test_secure_auth_key');
    if (!defined('LOGGED_IN_KEY')) define('LOGGED_IN_KEY', 'test_logged_in_key');

    // Mock WP Functions in Global Scope
    if (!function_exists('get_option')) {
        function get_option($key, $default = []) {
            if ($key === 'aperture_pro_settings') {
                return [
                    'storage_driver' => 'local',
                    'local_storage_path' => 'aperture-uploads',
                ];
            }
            return $default;
        }
    }

    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            return [
                'basedir' => sys_get_temp_dir(),
                'baseurl' => 'http://localhost/wp-content/uploads',
            ];
        }
    }

    if (!function_exists('trailingslashit')) {
        function trailingslashit($path) {
            return rtrim($path, '/') . '/';
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str) {
            return trim($str);
        }
    }

    if (!function_exists('update_option')) {
        function update_option($key, $val) {
            return true;
        }
    }

    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p($path) {
            return is_dir($path) || mkdir($path, 0755, true);
        }
    }
}

// Mock Logger in its namespace
namespace AperturePro\Helpers {
    class Logger
    {
        public static function log($level, $context, $message, $meta = []) {}
    }
}

namespace AperturePro\Tests {
    use AperturePro\Storage\StorageFactory;

    // Autoloader
    spl_autoload_register(function ($class) {
        if (strpos($class, 'AperturePro\\') === 0) {
            $className = substr($class, 12);
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', $className) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });

    // Benchmark
    $iterations = 10000;
    echo "Benchmarking StorageFactory::make() for $iterations iterations...\n";

    // Scenario 1: Inside Loop (Current)
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $storage = StorageFactory::make();
        // Simulate usage
        $name = $storage->getName();
    }
    $durationInside = microtime(true) - $start;
    echo "Inside Loop: " . number_format($durationInside, 5) . " seconds\n";

    // Scenario 2: Outside Loop (Optimized)
    $start = microtime(true);
    $storage = StorageFactory::make();
    for ($i = 0; $i < $iterations; $i++) {
        // Simulate usage
        $name = $storage->getName();
    }
    $durationOutside = microtime(true) - $start;
    echo "Outside Loop: " . number_format($durationOutside, 5) . " seconds\n";

    if ($durationInside > 0) {
        $improvement = ($durationInside - $durationOutside) / $durationInside * 100;
        echo "Improvement: " . number_format($improvement, 2) . "%\n";
        echo "Speedup: " . number_format($durationInside / $durationOutside, 2) . "x\n";
    }
}

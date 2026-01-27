<?php

/**
 * Benchmark: Storage instantiation vs. reuse (Local / Mocked Environment)
 *
 * GOAL:
 *  - Demonstrate the cost of repeated StorageFactory::create() calls
 *    (which include config decryption) vs. reusing a single instance.
 */

// Define ABSPATH to satisfy autoloader checks if any
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/../inc/autoloader.php';

// --- Mocks ---

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'aperture_pro_settings') {
            return [
                'storage_driver' => 'local',
                'local_storage_path' => 'aperture-pro',
            ];
        }
        if ($key === 'aperture_generated_salt') {
            return 'mock_salt';
        }
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        return true;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return [
            'basedir' => sys_get_temp_dir() . '/wp-content/uploads',
            'baseurl' => 'http://example.com/wp-content/uploads',
        ];
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        return true; // Pretend we made it
    }
}

if (!function_exists('set_url_scheme')) {
    function set_url_scheme($url, $scheme = null) {
        return $url;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return $url;
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'http://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'test_salt';
    }
}

// --- Benchmark ---

use AperturePro\Storage\StorageFactory;

function bench_storage_instantiation(int $iterations = 1000): void
{
    echo "Benchmarking StorageFactory::create() vs reuse ({$iterations} iterations)\n";

    // Baseline: repeated instantiation
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $s = StorageFactory::create();
    }
    $repeated = microtime(true) - $start;

    // Optimized: single instantiation, reused
    $start = microtime(true);
    $s = StorageFactory::create();
    for ($i = 0; $i < $iterations; $i++) {
        // no-op with $s
        $x = $s;
    }
    $reused = microtime(true) - $start;

    $ratio = $repeated > 0 ? $repeated / max($reused, 0.000001) : 0;

    echo "Repeated instantiation: " . number_format($repeated, 6) . " seconds\n";
    echo "Reused instance:        " . number_format($reused, 6) . " seconds\n";
    echo "Speedup:               ~" . number_format($ratio, 1) . "x\n";
}

bench_storage_instantiation(10000);

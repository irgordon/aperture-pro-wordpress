<?php

// tests/benchmark_schema_check.php

// Mock WP functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        // Return the current version to simulate up-to-date state
        // We match the constant in Schema class to avoid upgrade logic
        if ($option === 'aperture_pro_db_version') {
             return '1.0.16';
        }
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {
        return true;
    }
}

// These will be used in the optimization
if (!function_exists('is_admin')) {
    function is_admin() {
        global $mock_is_admin;
        return $mock_is_admin ?? false;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        global $mock_doing_ajax;
        return $mock_doing_ajax ?? false;
    }
}

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        global $mock_doing_cron;
        return $mock_doing_cron ?? false;
    }
}

// Load the class
require_once __DIR__ . '/../src/Installer/Schema.php';

use AperturePro\Installer\Schema;

// --- Config ---
$iterations = 100000; // 100k iterations to be quick but significant

// --- Benchmark ---
// Mock frontend request: not admin, not ajax, not cron
$mock_is_admin = false;
$mock_doing_ajax = false;
$mock_doing_cron = false;

echo "Benchmarking Schema::maybe_upgrade() over " . number_format($iterations) . " iterations...\n";
echo "Context: Frontend Request (is_admin=false)\n";

$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    Schema::maybe_upgrade();
}

$end = microtime(true);
$duration = $end - $start;

echo "Total Time: " . number_format($duration, 4) . " s\n";
echo "Avg Time per call: " . number_format(($duration / $iterations) * 1000000, 4) . " μs\n";
echo "Calls per second: " . number_format($iterations / $duration) . "\n";

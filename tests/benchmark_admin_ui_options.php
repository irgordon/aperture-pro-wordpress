<?php
/**
 * Benchmark for AdminUI Option Retrieval Optimization
 * Usage: php tests/benchmark_admin_ui_options.php
 */

namespace AperturePro\Tests;

// -----------------------------------------------------------------------------
// Mocks
// -----------------------------------------------------------------------------

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        // Simulate some "work" - WP options are often serialized or require lookup logic
        // We'll mimic a small cost.
        $data = [
            'storage_driver' => 'local',
            'cloud_provider' => 'cloudinary',
            'local_storage_path' => 'uploads/aperture',
            's3_bucket' => 'my-bucket',
            's3_region' => 'us-east-1',
            // ... add more dummy data to make the array realistic size
        ];
        // Simulate copy/overhead
        return unserialize(serialize($data));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        if ($selected === $current) {
            // echo "selected='selected'";
        }
    }
}

// -----------------------------------------------------------------------------
// Classes Under Test
// -----------------------------------------------------------------------------

class AdminUI_Before {
    const OPTION_KEY = 'aperture_pro_settings';

    public static function field_storage_driver()
    {
        // Current implementation: fetches option every time
        $opts = get_option(self::OPTION_KEY, []);
        $value = $opts['storage_driver'] ?? 'local';
        // Logic (minimized for benchmark)
        return $value;
    }
}

class AdminUI_After {
    const OPTION_KEY = 'aperture_pro_settings';

    // Optimization: Static Cache
    private static $_options = null;

    private static function get_options() {
        if (self::$_options === null) {
            self::$_options = get_option(self::OPTION_KEY, []);
        }
        return self::$_options;
    }

    public static function field_storage_driver()
    {
        // Optimized implementation: fetches from static cache
        $opts = self::get_options();
        $value = $opts['storage_driver'] ?? 'local';
        // Logic (minimized for benchmark)
        return $value;
    }

    public static function sanitize_options($input) {
        // Cache invalidation
        self::$_options = null;
        return $input;
    }
}

// -----------------------------------------------------------------------------
// Benchmark
// -----------------------------------------------------------------------------

$iterations = 50000;

echo "Benchmarking AdminUI Option Retrieval ($iterations iterations)...\n";

// --- BEFORE ---
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    AdminUI_Before::field_storage_driver();
}
$time_before = microtime(true) - $start;
echo sprintf("Before Optimization: %.4f seconds\n", $time_before);

// --- AFTER ---
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    AdminUI_After::field_storage_driver();
}
$time_after = microtime(true) - $start;
echo sprintf("After Optimization:  %.4f seconds\n", $time_after);

// --- RESULTS ---
if ($time_after > 0) {
    $improvement = $time_before / $time_after;
    echo sprintf("Improvement: %.2fx faster\n", $improvement);
}

<?php

// Mock Constants and Functions
define('APERTURE_PRO_VERSION', '1.0.0');
define('MONTH_IN_SECONDS', 2592000);

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return __DIR__ . '/../';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://example.com/wp-content/plugins/aperture-pro/';
    }
}

// Mock Transient Cache
$mock_transients = [];

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return isset($mock_transients[$transient]) ? $mock_transients[$transient] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}

// Mock file existence and content to avoid actual I/O in benchmark logic (we want to measure logic overhead/flow)
// SAFE: Use a temporary file in the tests directory, NOT the real asset path.
$dummySwPath = __DIR__ . '/sw_benchmark_tmp.js';
file_put_contents($dummySwPath, "console.log('./portal-app.js');");


function legacy_logic($isDebug) {
    global $dummySwPath;
    $cacheKey = 'ap_sw_' . APERTURE_PRO_VERSION;

    // Legacy Issue: Cache was skipped in debug mode
    if (!$isDebug) {
        $cachedContent = get_transient($cacheKey);
        if ($cachedContent) {
            return $cachedContent;
        }
    }

    // Use safe dummy path
    $swPath = $dummySwPath;

    if (file_exists($swPath)) {
        $content = file_get_contents($swPath);
        $pluginUrl = plugin_dir_url(__FILE__);

        $content = str_replace("'./portal-app.js'", "'" . $pluginUrl . "assets/js/portal-app.js'", $content);

        $ttl = $isDebug ? 30 : MONTH_IN_SECONDS;
        set_transient($cacheKey, $content, $ttl);
        return $content;
    }
    return '';
}

function current_logic($isDebug) {
    global $dummySwPath;
    $cacheKey = 'ap_sw_' . APERTURE_PRO_VERSION;

    // Optimization: Always check cache
    $cachedContent = get_transient($cacheKey);
    if ($cachedContent) {
        return $cachedContent;
    }

    // Use safe dummy path
    $swPath = $dummySwPath;

    if (file_exists($swPath)) {
        $content = file_get_contents($swPath);
        $pluginUrl = plugin_dir_url(__FILE__);

        $content = str_replace("'./portal-app.js'", "'" . $pluginUrl . "assets/js/portal-app.js'", $content);

        $ttl = $isDebug ? 30 : MONTH_IN_SECONDS;
        set_transient($cacheKey, $content, $ttl);
        return $content;
    }
    return '';
}

// --- Run Benchmark ---
$iterations = 1000;
$isDebug = true; // We are simulating the problem scenario

echo "Simulating Debug Mode (WP_DEBUG=true)\n";
echo "-------------------------------------\n";

// 1. Legacy Logic
// Clear cache initially
global $mock_transients;
$mock_transients = [];

// Prime the cache (simulating a previous request)
legacy_logic($isDebug);
// Note: legacy_logic writes to cache even in debug!

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    legacy_logic($isDebug);
}
$end = microtime(true);
$legacyTime = $end - $start;
echo "Legacy Logic Time (Hit Cache but Ignored): " . number_format($legacyTime, 5) . "s\n";


// 2. Current Logic
// Clear cache initially
$mock_transients = [];

// Prime the cache
current_logic($isDebug);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    current_logic($isDebug);
}
$end = microtime(true);
$currentTime = $end - $start;
echo "Current Logic Time (Hit Cache): " . number_format($currentTime, 5) . "s\n";


if ($currentTime > 0) {
    echo "Improvement: " . number_format($legacyTime / $currentTime, 1) . "x faster\n";
}

// Cleanup
if (file_exists($dummySwPath)) {
    unlink($dummySwPath);
}

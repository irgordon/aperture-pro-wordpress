<?php

// Mock WordPress functions
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

$transients = [];

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $transients;
        return isset($transients[$transient]) ? $transients[$transient] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $transients;
        $transients[$transient] = $value;
        return true;
    }
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Logic from PortalController::serve_service_worker (simplified for benchmark)
function serve_sw_logic() {
    $cachedContent = get_transient('ap_sw_cache');
    if ($cachedContent) {
        return $cachedContent;
    }

    $pluginDir = plugin_dir_path(__FILE__); // Adjusted for this script location relative to root
    $swPath = $pluginDir . 'assets/js/sw.js';

    if (file_exists($swPath)) {
        $content = file_get_contents($swPath);
        $pluginUrl = plugin_dir_url(__FILE__);

        $content = str_replace("'./portal-app.js'", "'" . $pluginUrl . "assets/js/portal-app.js'", $content);
        $content = str_replace("'../css/client-portal.css'", "'" . $pluginUrl . "assets/css/client-portal.css'", $content);
        $content = str_replace('"./portal-app.js"', '"' . $pluginUrl . 'assets/js/portal-app.js"', $content);
        $content = str_replace('"../css/client-portal.css"', '"' . $pluginUrl . 'assets/css/client-portal.css"', $content);

        set_transient('ap_sw_cache', $content, HOUR_IN_SECONDS);
        return $content;
    }
    return '';
}

// --- Benchmark ---

$iterations = 1000;

// 1. Cold Cache (Baseline) - we simulate this by clearing transient every time
echo "Benchmarking Cold Cache (File I/O + Processing)...\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    global $transients;
    $transients = []; // Clear cache
    serve_sw_logic();
}
$end = microtime(true);
$coldTime = $end - $start;
echo "Cold Cache Time ($iterations iterations): " . number_format($coldTime, 5) . "s\n";
echo "Avg per request: " . number_format(($coldTime / $iterations) * 1000, 4) . "ms\n";

// 2. Warm Cache - we populate cache once, then run loop
echo "\nBenchmarking Warm Cache (Memory Lookup)...\n";
// Prime cache
serve_sw_logic();

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    serve_sw_logic();
}
$end = microtime(true);
$warmTime = $end - $start;
echo "Warm Cache Time ($iterations iterations): " . number_format($warmTime, 5) . "s\n";
echo "Avg per request: " . number_format(($warmTime / $iterations) * 1000, 4) . "ms\n";

if ($warmTime > 0) {
    echo "\nImprovement: " . number_format($coldTime / $warmTime, 1) . "x faster\n";
}

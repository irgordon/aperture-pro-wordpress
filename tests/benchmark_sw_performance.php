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

// Create a dummy SW file for testing
$dummySwPath = __DIR__ . '/sw_perf_test.js';
$dummyContent = "/* Service Worker */\n";
for ($i = 0; $i < 500; $i++) {
    $dummyContent .= "console.log('Line $i');\n";
}
$dummyContent .= "importScripts('./portal-app.js');\n";
$dummyContent .= "importScripts('../css/client-portal.css');\n";
file_put_contents($dummySwPath, $dummyContent);


// 1. Legacy Logic (File Get Contents + Replace + Cache)
function legacy_sw_logic() {
    global $dummySwPath;
    $cachedContent = get_transient('ap_sw_cache');
    if ($cachedContent) {
        return $cachedContent;
    }

    if (file_exists($dummySwPath)) {
        ob_start(); // Capture output
        $content = file_get_contents($dummySwPath);
        $pluginUrl = plugin_dir_url(__FILE__);

        $content = str_replace("'./portal-app.js'", "'" . $pluginUrl . "assets/js/portal-app.js'", $content);
        $content = str_replace("'../css/client-portal.css'", "'" . $pluginUrl . "assets/css/client-portal.css'", $content);

        set_transient('ap_sw_cache', $content, HOUR_IN_SECONDS);
        echo $content;
        return ob_get_clean();
    }
    return '';
}

// 2. New Logic (readfile)
function new_sw_logic() {
    global $dummySwPath;
    if (file_exists($dummySwPath)) {
        ob_start();
        readfile($dummySwPath);
        return ob_get_clean();
    }
    return '';
}

// --- Benchmark ---

$iterations = 2000;

echo "Benchmarking SW Delivery Strategies ($iterations iterations)...\n";
echo "File size: " . round(filesize($dummySwPath) / 1024, 2) . " KB\n\n";

// A. Legacy Cold Cache (Worst Case)
echo "1. Legacy Cold Cache (File Read + Replace + Cache Write)...\n";
$start = microtime(true);
$memStart = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    global $transients;
    $transients = []; // Clear cache
    legacy_sw_logic();
}
$end = microtime(true);
$coldTime = $end - $start;
echo "Time: " . number_format($coldTime, 5) . "s\n";
echo "Avg:  " . number_format(($coldTime / $iterations) * 1000, 4) . "ms\n";

// B. Legacy Warm Cache (Best Case - Memory Bound)
echo "\n2. Legacy Warm Cache (Transient Lookup)...\n";
legacy_sw_logic(); // Prime cache
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    legacy_sw_logic();
}
$end = microtime(true);
$warmTime = $end - $start;
echo "Time: " . number_format($warmTime, 5) . "s\n";
echo "Avg:  " . number_format(($warmTime / $iterations) * 1000, 4) . "ms\n";


// C. New Logic (readfile - IO Bound but OS Cached)
echo "\n3. Optimized Logic (readfile)...\n";
$start = microtime(true);
$peakStart = memory_get_peak_usage();
for ($i = 0; $i < $iterations; $i++) {
    new_sw_logic();
}
$end = microtime(true);
$newTime = $end - $start;
$peakEnd = memory_get_peak_usage();

echo "Time: " . number_format($newTime, 5) . "s\n";
echo "Avg:  " . number_format(($newTime / $iterations) * 1000, 4) . "ms\n";
echo "Peak Mem Delta: " . number_format(($peakEnd - $peakStart) / 1024, 2) . " KB\n";


if ($newTime > 0) {
    echo "\nComparison vs Cold Cache: " . number_format($coldTime / $newTime, 1) . "x faster\n";
    echo "Comparison vs Warm Cache: " . number_format($warmTime / $newTime, 1) . "x (speed difference)\n";
}

// Cleanup
unlink($dummySwPath);

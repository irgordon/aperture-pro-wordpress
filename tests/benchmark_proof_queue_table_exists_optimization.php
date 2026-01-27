<?php

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

use AperturePro\Proof\ProofQueue;

// Mock WP Constants
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Mock WP Functions
$mock_transients = [];

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return isset($mock_transients[$transient]) ? $mock_transients[$transient] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {}
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) { return false; }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}

// Mock WPDB
class MockWPDB {
    public $prefix = 'wp_';

    public function prepare($query, ...$args) {
        return vsprintf(str_replace('%s', "'%s'", $query), $args);
    }

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function get_var($query) {
        // Simulate DB Latency
        usleep(10000); // 10ms latency

        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
             // Simulate table exists
             return 'wp_ap_proof_queue';
        }
        return null;
    }

    public function query($query) {
        return true;
    }
}

global $wpdb;
$wpdb = new MockWPDB();


// --- Benchmark Logic ---

// Helper to reset static property
function reset_static_cache() {
    $reflection = new ReflectionClass(ProofQueue::class);
    $property = $reflection->getProperty('tableExistsCache');
    $property->setAccessible(true);
    $property->setValue(null, null);
}

echo "Benchmarking ProofQueue::tableExists()...\n";

$iterations = 50;
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // We intentionally reset the static cache to simulate new requests
    // or to force the code to rely on the persistent cache (transient)
    // instead of the static request-level cache.
    reset_static_cache();

    // We use reflection to call the protected method
    $method = new ReflectionMethod(ProofQueue::class, 'tableExists');
    $method->setAccessible(true);
    $exists = $method->invoke(null);

    if (!$exists) {
        echo "Error: Table should exist.\n";
    }
}

$end = microtime(true);
$duration = $end - $start;

echo "Total time for $iterations iterations: " . number_format($duration, 4) . "s\n";
echo "Average time per iteration: " . number_format(($duration / $iterations) * 1000, 2) . "ms\n";

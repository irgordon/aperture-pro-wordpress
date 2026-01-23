<?php
/**
 * Benchmark for Queue Scaling (Options vs DB)
 * Usage: php tests/benchmark_queue_scaling.php
 */

require_once __DIR__ . '/../src/Proof/ProofQueue.php';
require_once __DIR__ . '/../src/Proof/ProofService.php';

// --- Mocks ---
$mock_options = [];
$mock_transients = [];
global $wpdb_queries;
$wpdb_queries = 0;

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) { return false; }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) { return true; }
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}

// Mock WPDB
class MockWPDB {
    public $prefix = 'wp_';
    public $last_error = '';

    // Simulate DB table exists for ap_proof_queue
    public function get_var($query) {
        global $wpdb_queries;
        $wpdb_queries++;
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
             return 'wp_ap_proof_queue'; // Table exists
        }
        if (strpos($query, 'SELECT COUNT(*)') !== false) {
            return 10;
        }
        return 0;
    }

    public function query($query) {
        global $wpdb_queries;
        $wpdb_queries++;
        // Simulate INSERT IGNORE success
        return true;
    }

    public function prepare($query, ...$args) {
        // Naive printf
        return vsprintf(str_replace(['%s', '%d'], ['%s', '%d'], $query), $args);
    }

    public function esc_like($text) { return $text; }

    public function get_results($query) {
        global $wpdb_queries;
        $wpdb_queries++;
        return [];
    }
}

global $wpdb;
$wpdb = new MockWPDB();

use AperturePro\Proof\ProofQueue;

// --- Benchmark ---

$batchSize = 200;
echo "Benchmarking optimized DB queue with batch of $batchSize items...\n";

$start = microtime(true);
for ($i = 0; $i < $batchSize; $i++) {
    // Mock project_id and image_id
    ProofQueue::add(1, $i + 1000);
}
$end = microtime(true);

echo sprintf("Time taken for $batchSize items: %.4f seconds\n", $end - $start);
echo "WPDB Queries made: $wpdb_queries\n";

if ($wpdb_queries >= $batchSize) {
    echo "SUCCESS: Using WPDB for inserts.\n";
} else {
    echo "FAIL: Not using WPDB.\n";
}

// Verify legacy fallback
// Unset WPDB or mock failure to test fallback?
// For now, this confirms the optimized path is active.

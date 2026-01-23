<?php
/**
 * Test for ProofQueue Retry Logic
 * Usage: php tests/test_queue_retries.php
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
class MockWPDBRetry {
    public $prefix = 'wp_';

    public function get_var($query) {
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
             return 'wp_ap_proof_queue';
        }
        return 0;
    }

    public function query($query) {
        echo "WPDB Query: " . substr($query, 0, 100) . "...\n";
        return true;
    }

    public function prepare($query, ...$args) {
        return vsprintf(str_replace(['%s', '%d'], ['%s', '%d'], $query), $args);
    }

    public function esc_like($text) { return $text; }

    public function get_results($query) {
        echo "WPDB Select: " . substr($query, 0, 100) . "...\n";
        // Return nothing so we verify the query structure
        return [];
    }
}

global $wpdb;
$wpdb = new MockWPDBRetry();

use AperturePro\Proof\ProofQueue;

echo "Testing fetchBatch query...\n";
ProofQueue::fetchBatch(10);
// Expect: SELECT * FROM wp_ap_proof_queue WHERE attempts < 3 ...

echo "\nTesting cleanupMaxRetries...\n";
// Reflect to call protected method
$reflection = new ReflectionClass(ProofQueue::class);
$method = $reflection->getMethod('cleanupMaxRetries');
$method->setAccessible(true);
$method->invoke(null, [1, 2, 3]);
// Expect: DELETE FROM wp_ap_proof_queue WHERE attempts >= 3 AND id IN (1,2,3)

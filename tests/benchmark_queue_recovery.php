<?php
/**
 * Benchmark for ProofQueue Recovery Performance
 *
 * Compares the performance of processing a batch of items when the table is initially missing.
 *
 * Scenarios:
 * 1. Legacy Fallback (Simulated): Writing to wp_options for every item.
 * 2. Auto-Recovery (Optimized): Recovering table once, then writing to DB.
 */

namespace AperturePro\Installer;

class Schema
{
    public static function activate(): void
    {
        global $wpdb;
        $wpdb->tableExists = true;
        // Simulate schema creation overhead
        usleep(5000); // 5ms
    }
}

namespace AperturePro\Proof;

use AperturePro\Installer\Schema;

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// --- Mocks ---
$mock_options = [];
$mock_transients = [];

function get_option($option, $default = false) {
    global $mock_options;
    return $mock_options[$option] ?? $default;
}

function update_option($option, $value, $autoload = null) {
    global $mock_options;
    $mock_options[$option] = $value;
    // Simulate DB write latency for options
    usleep(500);
    return true;
}

function get_transient($transient) {
    global $mock_transients;
    return $mock_transients[$transient] ?? false;
}

function set_transient($transient, $value, $expiration = 0) {
    global $mock_transients;
    $mock_transients[$transient] = $value;
    return true;
}

function delete_transient($transient) {
    global $mock_transients;
    unset($mock_transients[$transient]);
    return true;
}

function current_time($type) {
    return date('Y-m-d H:i:s');
}

function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($time, $hook) { return true; }

class MockWPDB {
    public $prefix = 'wp_';
    public $tableExists = false;

    public function prepare($query, ...$args) { return $query; }
    public function esc_like($text) { return $text; }

    public function get_var($query) {
        if (strpos($query, "SHOW TABLES LIKE") !== false) {
            return $this->tableExists ? 'wp_ap_proof_queue' : null;
        }
        return null;
    }

    public function query($query) {
        if (strpos($query, "INSERT IGNORE INTO wp_ap_proof_queue") !== false) {
            if ($this->tableExists) {
                // Simulate DB Insert Latency (much faster than serialized option update)
                usleep(50);
                return 1;
            } else {
                return false;
            }
        }
        return true;
    }
}

global $wpdb;
$wpdb = new MockWPDB();

$itemCount = 100;

echo "--- Benchmark: Queue Recovery ($itemCount items) ---\n";

// --- Scenario 1: Legacy Fallback (Baseline) ---
// We simulate this by forcing tableExists to always be false and NOT recovering
// (Hard to force logic change, so we simulate the cost manually)

echo "Scenario 1: Legacy Fallback (Simulated Baseline)\n";
$start = microtime(true);
$mock_options[ProofQueue::QUEUE_OPTION] = [];
for ($i = 0; $i < $itemCount; $i++) {
    // Simulate what addToLegacyQueue does: read option, append, write option
    $q = $mock_options[ProofQueue::QUEUE_OPTION];
    $q["1:$i"] = ['project_id' => 1, 'image_id' => $i];
    // Simulate update_option latency
    usleep(500);
    $mock_options[ProofQueue::QUEUE_OPTION] = $q;
}
$end = microtime(true);
$baselineTime = $end - $start;
echo sprintf("Time: %.4f seconds\n", $baselineTime);


// --- Scenario 2: Optimized Auto-Recovery ---
echo "\nScenario 2: Optimized Auto-Recovery (Actual Code)\n";
// Reset
$wpdb->tableExists = false;
$mock_options[ProofQueue::QUEUE_OPTION] = [];
delete_transient('ap_proof_queue_table_exists');

$start = microtime(true);
for ($i = 0; $i < $itemCount; $i++) {
    // This will trigger recovery on first item, then use DB for rest
    ProofQueue::add(1, $i);
}
$end = microtime(true);
$optimizedTime = $end - $start;

echo sprintf("Time: %.4f seconds\n", $optimizedTime);

if ($optimizedTime > 0) {
    echo sprintf("Improvement: %.2fx faster\n", $baselineTime / $optimizedTime);
}

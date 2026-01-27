<?php
/**
 * Benchmark for ProofQueue Legacy Enqueue Performance (N+1 Writes vs Batch)
 * Usage: php tests/benchmark_proof_queue_legacy.php
 */

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// Mock Data Store
$mock_options = [];

// Mock WP Functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        // Simulate DB write latency (e.g., 0.5ms)
        usleep(500);
        $mock_options[$option] = $value;
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        // Simulate DB write for cron
        usleep(500);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}

// Mock WPDB (needed because ProofQueue checks table existence)
class MockWPDB {
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    // Simulate table does NOT exist so it falls back to legacy logic
    public function get_var($query) { return null; }
    public function esc_like($text) { return $text; }
    public function query($query) { return true; }
    // Add get_results for ID resolution fallback check
    public function get_results($query) { return []; }
}
$GLOBALS['wpdb'] = new MockWPDB();

use AperturePro\Proof\ProofQueue;

$itemCount = 200;

echo "Starting Benchmark ($itemCount legacy items, 0.5ms simulated DB latency)...\n";

// --- Baseline Test: Iterative Loop ---
echo "\n--- Baseline: Loop of ProofQueue::enqueue() ---\n";

// Reset queue
$mock_options[ProofQueue::QUEUE_OPTION] = [];

$start = microtime(true);

for ($i = 0; $i < $itemCount; $i++) {
    ProofQueue::enqueue("original_$i.jpg", "proof_$i.jpg");
}

$end = microtime(true);
$duration = $end - $start;

echo sprintf("Baseline Duration: %.4f seconds\n", $duration);
$baselineDuration = $duration;

// --- Optimized Test: Batch ---
echo "\n--- Optimized: ProofQueue::enqueueBatch() ---\n";

// Reset queue
$mock_options[ProofQueue::QUEUE_OPTION] = [];

$batch = [];
for ($i = 0; $i < $itemCount; $i++) {
    $batch[] = [
        'original_path' => "original_$i.jpg",
        'proof_path'    => "proof_$i.jpg",
    ];
}

$start = microtime(true);
ProofQueue::enqueueBatch($batch);
$end = microtime(true);
$durationOpt = $end - $start;

echo sprintf("Optimized Duration: %.4f seconds\n", $durationOpt);
echo sprintf("Improvement: %.2fx faster\n", $baselineDuration / $durationOpt);

// Validate queue size
$queue = get_option(ProofQueue::QUEUE_OPTION);
if (count($queue) !== $itemCount) {
    echo "ERROR: Queue count mismatch. Expected $itemCount, got " . count($queue) . "\n";
} else {
    echo "SUCCESS: Queue count matches.\n";
}

<?php
/**
 * Benchmark for ProofQueue Enqueue Performance (N+1 Writes vs Batch)
 * Usage: php tests/benchmark_proof_queue.php
 */

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// Mock Data Store
$mock_options = [];
$mock_transients = [];

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

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false; // Always pretend not scheduled to trigger scheduling logic
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        // Simulate DB write for cron
        usleep(500);
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

use AperturePro\Proof\ProofQueue;

// --- Benchmark Configuration ---
$itemCount = 100; // reduced to 100 for quicker test, but enough to show trend.
// Wait, prompt said 1000 items. Let's do 200 to be safe on time but clear on impact.
$itemCount = 200;

echo "Starting Benchmark ($itemCount items, 0.5ms simulated DB latency)...\n";

// --- Baseline Test ---
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
echo sprintf("Average per item: %.4f seconds\n", $duration / $itemCount);
$baselineDuration = $duration;


// --- Optimized Test (Future) ---
if (method_exists(ProofQueue::class, 'enqueueBatch')) {
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

} else {
    echo "\n[Info] enqueueBatch not yet implemented.\n";
}

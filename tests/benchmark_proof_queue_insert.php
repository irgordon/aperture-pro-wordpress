<?php
/**
 * Benchmark Proof Queue Insertion
 * Usage: php tests/benchmark_proof_queue_insert.php
 */

namespace AperturePro\Storage {
    class StorageFactory {
        public static function create() { return null; }
    }
}

namespace AperturePro\Proof {
    // Override function_exists to allow mocking
    function function_exists($func) {
        return \function_exists($func);
    }
}

namespace {
    // Mocks
    $mock_options = [];
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }
    function current_time($type, $gmt = 0) { return date('Y-m-d H:i:s'); }
    function wp_next_scheduled($hook, $args = []) { return false; }
    function wp_schedule_single_event($timestamp, $hook, $args = []) { return true; }
    function get_transient($transient) { return false; }
    function set_transient($transient, $value, $expiration) { return true; }
    function delete_transient($transient) { return true; }
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
    function apply_filters($tag, $value) { return $value; }
}

namespace AperturePro\Test {

    require_once __DIR__ . '/../src/Proof/ProofQueue.php';
    // Dummy Logger
    class MockLogger {
        public static function log($level, $context, $message, $data = []) {}
    }
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class_alias('AperturePro\Test\MockLogger', 'AperturePro\Helpers\Logger');
    }

    use AperturePro\Proof\ProofQueue;

    class BenchmarkDB {
        public $prefix = 'wp_';
        public $query_count = 0;
        public $latency_ms = 1; // 1ms latency per query

        public function prepare($query, ...$args) { return $query; }
        public function get_var($query) {
            // Mock tableExists -> returns table name if query is SHOW TABLES
            if (strpos($query, 'SHOW TABLES') !== false) {
                return 'wp_ap_proof_queue';
            }
            return null;
        }
        public function esc_like($text) { return $text; }

        public function query($query) {
            $this->query_count++;
            usleep($this->latency_ms * 1000);
            return true;
        }
        public function get_results($query) { return []; }
    }

    $wpdb = new BenchmarkDB();
    $GLOBALS['wpdb'] = $wpdb;

    // Generate test data
    $itemCount = 1000;
    $items = [];
    for ($i = 0; $i < $itemCount; $i++) {
        $items[] = [
            'original_path' => "projects/1/img_{$i}.jpg",
            'proof_path'    => "proofs/1/img_{$i}_proof.jpg",
            'project_id'    => 1,
            'image_id'      => $i + 1
        ];
    }

    echo "Benchmarking with $itemCount items and {$wpdb->latency_ms}ms simulated DB latency...\n\n";

    // --- Baseline: Loop with single inserts ---
    $wpdb->query_count = 0;
    $start = microtime(true);

    foreach ($items as $item) {
        if (isset($item['project_id'], $item['image_id'])) {
            ProofQueue::add((int)$item['project_id'], (int)$item['image_id']);
        } else {
            ProofQueue::enqueue($item['original_path'], $item['proof_path']);
        }
    }

    $baselineTime = microtime(true) - $start;
    $baselineQueries = $wpdb->query_count;

    echo "Baseline (Loop):\n";
    echo "  Time: " . number_format($baselineTime, 4) . " s\n";
    echo "  Queries: $baselineQueries\n";

    // Reset mocks
    $wpdb->query_count = 0;

    // --- Optimized: Batch insert ---
    $start = microtime(true);

    ProofQueue::enqueueBatch($items);

    $optimizedTime = microtime(true) - $start;
    $optimizedQueries = $wpdb->query_count;

    echo "\nOptimized (Batch):\n";
    echo "  Time: " . number_format($optimizedTime, 4) . " s\n";
    echo "  Queries: $optimizedQueries\n";

    // Comparison
    $timeDiff = $baselineTime - $optimizedTime;
    $queryDiff = $baselineQueries - $optimizedQueries;

    echo "\nImprovement:\n";
    echo "  Time saved: " . number_format($timeDiff, 4) . " s\n";
    echo "  Queries saved: $queryDiff\n";

    if ($optimizedQueries < $baselineQueries) {
        echo "\nRESULT: PASS - Queries reduced significantly.\n";
    } else {
        echo "\nRESULT: FAIL - No reduction in queries.\n";
        exit(1);
    }
}

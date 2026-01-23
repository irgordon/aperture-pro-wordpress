<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($l, $c, $m, $ctx=[]) {}
    }
}

namespace AperturePro\Storage {
    class StorageFactory {
        public static function create() { return new \stdClass(); }
    }
}

namespace AperturePro\Proof {
    class ProofService {
        public static function getProofPathForOriginal($p) { return $p . '_proof'; }
        public static function generateBatch($items, $s) { return []; }
    }
}

namespace {
    // Mock WP
    $mock_options = [];
    class MockWPDB {
        public $prefix = 'wp_';
        public $last_error = '';
        public function prepare($q, ...$args) { return $q; }
        public function esc_like($s) { return $s; }
        public function get_var($q) { return null; } // Table does not exist
        public function query($q) { return false; }
        public function get_results($q) { return []; }
    }
    $wpdb = new MockWPDB();

    function get_option($key, $default = []) {
        global $mock_options;
        return $mock_options[$key] ?? $default;
    }
    function update_option($key, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$key] = $value;
        return true;
    }
    function current_time($type, $gmt=0) { return date('Y-m-d H:i:s'); }
    function wp_next_scheduled($hook) { return false; }
    function wp_schedule_single_event($ts, $hook) {}
    function get_transient($k) { return false; }
    function set_transient($k, $v, $e) {}
    function delete_transient($k) {}

    // Load Class
    require_once __DIR__ . '/../src/Proof/ProofQueue.php';

    use AperturePro\Proof\ProofQueue;

    class TestProofQueue extends ProofQueue {
        public static function testAdd($p, $i) {
            return self::addToLegacyQueue($p, $i);
        }
    }

    // Test 1: Migration from numeric to keyed
    echo "Test 1: Migration from numeric to keyed...\n";
    $legacy_queue = [];
    for ($i=1; $i<=3; $i++) {
        $legacy_queue[] = [
            'project_id' => $i,
            'image_id' => $i,
            'created_at' => '2023-01-01 00:00:00',
            'attempts' => 0
        ];
    }
    // Set option as numeric array
    $mock_options[ProofQueue::QUEUE_OPTION] = $legacy_queue;

    // Add existing item (should find it)
    $start = microtime(true);
    // Use subclass to call protected method directly to skip table check overhead for microbenchmark?
    // Or just rely on mocked wpdb. Let's use subclass to be explicit about testing the method.
    TestProofQueue::testAdd(2, 2);
    $end = microtime(true);
    echo "  Time to add existing (with migration): " . number_format($end - $start, 5) . "s\n";

    $stored = $mock_options[ProofQueue::QUEUE_OPTION];
    // Check if stored is keyed
    $keys = array_keys($stored);
    $hasStringKey = false;
    foreach($keys as $k) { if(is_string($k)) $hasStringKey = true; }

    if ($hasStringKey && isset($stored['2:2'])) {
        echo "  PASS: Queue migrated to keyed map.\n";
    } else {
        echo "  FAIL: Queue not keyed properly.\n";
        print_r($stored);
        exit(1);
    }

    // Test 2: Add new item (already keyed)
    echo "Test 2: Add new item to keyed queue...\n";
    TestProofQueue::testAdd(4, 4);
    $stored = $mock_options[ProofQueue::QUEUE_OPTION];
    if (isset($stored['4:4'])) {
        echo "  PASS: New item added with key.\n";
    } else {
        echo "  FAIL: New item not found.\n";
        exit(1);
    }

    // Test 3: Benchmark Large Queue
    echo "Test 3: Benchmark Large Queue (5000 items)...\n";
    // Setup large keyed queue
    $large_queue = [];
    for ($i=0; $i<5000; $i++) {
        $large_queue["{$i}:{$i}"] = ['project_id'=>$i, 'image_id'=>$i];
    }
    $mock_options[ProofQueue::QUEUE_OPTION] = $large_queue;

    $start = microtime(true);
    for ($k=0; $k<1000; $k++) {
        // Add existing items
        TestProofQueue::testAdd($k, $k);
    }
    $duration = microtime(true) - $start;
    echo "  Time to check 1000 existing items: " . number_format($duration, 4) . "s\n";

    if ($duration > 0.1) {
        echo "  WARNING: Might be slow? (Expect < 0.1s)\n";
    } else {
        echo "  PASS: Performance is good.\n";
    }

    // Test 4: Verify processLegacyQueue behavior (retries maintain keys)
    echo "Test 4: Verify processLegacyQueue retries maintain keys...\n";
    // Setup a failing item
    $mock_options[ProofQueue::QUEUE_OPTION] = [
        '99:99' => ['project_id'=>99, 'image_id'=>99, 'attempts'=>0]
    ];

    // Call processQueue. Mocked tableExists returns false (WPDB returns null).
    ProofQueue::processQueue();

    $stored = $mock_options[ProofQueue::QUEUE_OPTION];
    if (isset($stored['99:99']) && $stored['99:99']['attempts'] == 1) {
        echo "  PASS: Retry maintained key and incremented attempts.\n";
    } else {
        echo "  FAIL: Retry lost key or failed to increment.\n";
        print_r($stored);
        exit(1);
    }
}

<?php
/**
 * Test Proof Queue Batch Processing
 * Usage: php tests/test_proof_queue_batch.php
 */

namespace {
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
}

namespace AperturePro\Test {

    require_once __DIR__ . '/../src/Proof/ProofQueue.php';

    use AperturePro\Proof\ProofQueue;

    // 1. Test Simple Batch Enqueue
    echo "Test 1: Simple Batch Enqueue\n";
    $items = [
        ['original_path' => 'orig1.jpg', 'proof_path' => 'proof1.jpg'],
        ['original_path' => 'orig2.jpg', 'proof_path' => 'proof2.jpg'],
    ];
    ProofQueue::enqueueBatch($items);

    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) === 2 && $queue[0]['proof_path'] === 'proof1.jpg') {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        print_r($queue);
        exit(1);
    }

    // 2. Test Deduplication against Existing Queue
    echo "Test 2: Deduplication against Existing\n";
    // Enqueue mixed (one new, one existing)
    $items2 = [
        ['original_path' => 'orig2.jpg', 'proof_path' => 'proof2.jpg'], // Duplicate
        ['original_path' => 'orig3.jpg', 'proof_path' => 'proof3.jpg'], // New
    ];
    ProofQueue::enqueueBatch($items2);

    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) === 3 && $queue[2]['proof_path'] === 'proof3.jpg') {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        print_r($queue);
        exit(1);
    }

    // 3. Test Deduplication within Batch
    echo "Test 3: Deduplication within Batch\n";
    $items3 = [
        ['original_path' => 'orig4.jpg', 'proof_path' => 'proof4.jpg'],
        ['original_path' => 'orig4.jpg', 'proof_path' => 'proof4.jpg'], // Duplicate in batch
    ];
    ProofQueue::enqueueBatch($items3);

    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) === 4 && $queue[3]['proof_path'] === 'proof4.jpg') {
        echo "PASS\n";
    } else {
        echo "FAIL\n";
        print_r($queue);
        exit(1);
    }

    // 4. Test Queue Size Limit
    echo "Test 4: Queue Size Limit\n";
    $GLOBALS['mock_options'] = []; // Reset queue
    $items4 = [];
    for ($i = 0; $i < ProofQueue::MAX_QUEUE_SIZE + 50; $i++) {
        $items4[] = ['original_path' => "orig{$i}.jpg", 'proof_path' => "proof{$i}.jpg"];
    }
    ProofQueue::enqueueBatch($items4);

    $queue = \get_option(ProofQueue::QUEUE_OPTION);
    if (count($queue) === ProofQueue::MAX_QUEUE_SIZE) {
        echo "PASS\n";
    } else {
        echo "FAIL - Queue size is " . count($queue) . ", expected " . ProofQueue::MAX_QUEUE_SIZE . "\n";
        print_r($queue);
        exit(1);
    }

    echo "ALL TESTS PASSED\n";
}

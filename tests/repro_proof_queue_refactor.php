<?php
/**
 * Regression Test for ProofQueue::enqueueBatch Refactor
 * Usage: php tests/repro_proof_queue_refactor.php
 */

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// --- Mocks ---

$mock_options = [];
$mock_queries = []; // Log DB queries

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
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        return true;
    }
}

class MockWPDB_Repro {
    public $prefix = 'wp_';
    public $last_error = '';

    public function prepare($query, ...$args) {
        // Simple placeholder replacement for logging
        $interpolated = $query;
        foreach ($args as $arg) {
            $arg = is_string($arg) ? "'$arg'" : $arg;
            $interpolated = preg_replace('/%[sd]/', $arg, $interpolated, 1);
        }
        return $interpolated;
    }

    public function get_var($query) {
        // Simulate table existence check
        if (strpos($query, "SHOW TABLES LIKE") !== false) {
            return 'wp_ap_proof_queue'; // Table exists
        }
        if (strpos($query, "SELECT COUNT(*)") !== false) {
            return 0;
        }
        return null;
    }

    public function esc_like($text) { return $text; }

    public function query($query) {
        global $mock_queries;
        $mock_queries[] = $query;
        return true;
    }

    public function get_results($query) {
        // Simulate ID resolution
        // Query usually looks like: SELECT ... FROM ... WHERE storage_key_original IN (...)
        if (strpos($query, "storage_key_original IN") !== false) {
            // Let's pretend we have these files in DB
            $mockRows = [];
            // Parse for paths in the query if needed, or just return fixed rows that match test data
            // Test data will be 'path/to/image1.jpg' and 'path/to/image2.jpg'

            if (strpos($query, "'path/to/image1.jpg'") !== false) {
                $o1 = new stdClass();
                $o1->image_id = 101;
                $o1->storage_key_original = 'path/to/image1.jpg';
                $o1->project_id = 10;
                $mockRows[] = $o1;
            }
            if (strpos($query, "'path/to/image2.jpg'") !== false) {
                $o2 = new stdClass();
                $o2->image_id = 102;
                $o2->storage_key_original = 'path/to/image2.jpg';
                $o2->project_id = 10;
                $mockRows[] = $o2;
            }
            return $mockRows;
        }
        return [];
    }
}

$GLOBALS['wpdb'] = new MockWPDB_Repro();

use AperturePro\Proof\ProofQueue;

// --- Test Execution ---

echo "Starting ProofQueue Refactor Regression Test...\n";

// 1. Setup Test Data
$items = [
    [
        'original_path' => 'path/to/image1.jpg', // Should resolve to ID 101
        'proof_path'    => 'proofs/image1_proof.jpg'
    ],
    [
        'original_path' => 'path/to/image2.jpg', // Should resolve to ID 102
        'proof_path'    => 'proofs/image2_proof.jpg'
    ],
    [
        'original_path' => 'path/to/image3.jpg', // Does NOT exist in DB, should go to legacy
        'proof_path'    => 'proofs/image3_proof.jpg'
    ]
];

// Clear state
$mock_options[ProofQueue::QUEUE_OPTION] = [];
$mock_queries = [];

// 2. Run addBatch
ProofQueue::addBatch($items);

// 3. Verification

$legacyQueue = get_option(ProofQueue::QUEUE_OPTION, []);
$dbInserts = array_filter($mock_queries, function($q) {
    return strpos($q, "INSERT IGNORE INTO wp_ap_proof_queue") !== false;
});

echo "Legacy Queue Count: " . count($legacyQueue) . "\n";
echo "DB Inserts Count:   " . count($dbInserts) . "\n";

// Analyze Results
$legacyKeys = array_map(function($i) { return $i['original_path'] ?? 'unknown'; }, $legacyQueue);
echo "Legacy Items: " . implode(', ', $legacyKeys) . "\n";

// Assertions
$errors = [];

// Assertion 1: Legacy queue should only contain the item that couldn't be resolved (image3)
if (count($legacyQueue) !== 1) {
    $errors[] = "Expected legacy queue count to be 1, got " . count($legacyQueue);
}

$legacyItem = reset($legacyQueue);
if (!isset($legacyItem['original_path']) || $legacyItem['original_path'] !== 'path/to/image3.jpg') {
    $errors[] = "Expected legacy item to be 'path/to/image3.jpg', got " . ($legacyItem['original_path'] ?? 'unknown');
}

// Assertion 2: DB queue should have received the resolved items (image1, image2)
if (count($dbInserts) === 0) {
    $errors[] = "Expected at least one DB INSERT query, got 0";
} else {
    // Check the content of the insert query
    $insertQuery = reset($dbInserts);
    if (strpos($insertQuery, '101') === false || strpos($insertQuery, '102') === false) {
        $errors[] = "DB INSERT query does not contain expected IDs (101, 102). Query: $insertQuery";
    }
}

if (!empty($errors)) {
    echo "FAIL: Regression test failed.\n";
    foreach ($errors as $error) {
        echo " - $error\n";
    }
    exit(1);
}

echo "PASS: All assertions met. ID resolution and routing works as expected.\n";
exit(0);

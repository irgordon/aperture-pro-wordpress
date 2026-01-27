<?php
/**
 * Reproduction/Verification Test for ProofQueue::enqueueBatch Refactor
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

echo "Starting ProofQueue Refactor Test...\n";

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

// 2. Run enqueueBatch
ProofQueue::enqueueBatch($items);

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

// Expectation BEFORE FIX:
// - All 3 items in legacy queue (count 3).
// - 0 DB inserts.

// Expectation AFTER FIX:
// - 1 item in legacy queue (image3).
// - 1 DB insert (batch insert for image1 and image2).

if (count($legacyQueue) === 3 && count($dbInserts) === 0) {
    echo "STATUS: CURRENT BEHAVIOR (Legacy fallback for all items).\n";
} elseif (count($legacyQueue) === 1 && count($dbInserts) > 0) {
    echo "STATUS: FIXED BEHAVIOR (IDs resolved and pushed to DB).\n";
    if (strpos($dbInserts[0] ?? '', '101') !== false && strpos($dbInserts[0] ?? '', '102') !== false) {
        echo " - Verified correct IDs in INSERT query.\n";
    } else {
        echo " - WARNING: IDs missing from INSERT query.\n";
    }
} else {
    echo "STATUS: UNEXPECTED STATE.\n";
}

<?php
namespace AperturePro\Storage;
class StorageFactory {
    public static function create() { return new class {}; }
}

namespace AperturePro\Helpers;
class Logger {
    public static function log($l, $c, $m, $ctx=[]) {}
}

namespace AperturePro\Proof;
class ProofService {
    public static function generateBatch($items, $storage) { return []; }
    public static function getProofPathForOriginal($path) { return 'proofs/' . basename($path); }
}

namespace AperturePro\Proof; // Back to main namespace for the test

require_once __DIR__ . '/../src/Proof/ProofQueue.php';

// Mock WP
$mock_options = [];
$mock_transients = [];
global $wpdb;

if (!class_exists('MockWPDB')) {
    class MockWPDB {
        public $prefix = 'wp_';
        public $queries = [];

        public function prepare($query, ...$args) {
            // Simple simulation of prepare
            foreach ($args as $arg) {
                // This is a very rough mock, replacing %s and %d
                // It's just enough to make the query string look somewhat valid for regex checks if needed
                // But mostly we just care about the method existing.
            }
            return $query;
        }

        public function esc_like($text) { return $text; }

        public function get_var($query) {
            if (strpos($query, "SHOW TABLES LIKE") !== false) {
                return 'wp_ap_proof_queue'; // Table exists
            }
            if (strpos($query, "SELECT COUNT(*)") !== false) {
                return 0;
            }
            return null;
        }

        public function query($query) {
            $this->queries[] = $query;
            return true;
        }

        public function get_results($query) {
             return [];
        }
    }
}
$GLOBALS['wpdb'] = new MockWPDB();


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
    function wp_next_scheduled($hook, $args = []) { return false; }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) { return true; }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt=0) { return '2023-01-01 00:00:00'; }
}


// --- Setup Data ---
$legacyQueue = [];
for ($i = 0; $i < 1000; $i++) {
    $legacyQueue[] = [
        'project_id' => 1,
        'image_id'   => $i + 1,
        'created_at' => current_time('mysql'),
        'attempts'   => 0
    ];
}
$mock_options[ProofQueue::QUEUE_OPTION] = $legacyQueue;

echo "Initial Legacy Queue Size: " . count($mock_options[ProofQueue::QUEUE_OPTION]) . "\n";

// --- Run Processing ---
$start = microtime(true);
ProofQueue::processQueue();
$end = microtime(true);

$finalQueue = $mock_options[ProofQueue::QUEUE_OPTION];
$count = count($finalQueue);

echo "Final Legacy Queue Size: $count\n";
echo "Time Taken: " . number_format($end - $start, 4) . "s\n";

if ($count < 50) {
    echo "SUCCESS: Legacy queue migrated/emptied.\n";
} else {
    echo "BASELINE: Legacy queue still has items (Processed only batch size).\n";
}

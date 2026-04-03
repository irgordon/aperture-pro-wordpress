<?php

namespace AperturePro\Helpers {
    // Mock WPDB Class
    class MockWPDB {
        public $prefix = 'wp_';
        public $query_count = 0;

        public function insert($table, $data, $format = null) {
            return 1;
        }

        public function prepare($query, ...$args) {
            return $query;
        }

        public function get_var($query) {
            $this->query_count++;
            // Simulate that the item is NOT found initially, or simulate checking for duplicates
            // The query being optimized is: SELECT id FROM $table WHERE dedupe_hash = %s ...
            if (strpos($query, 'dedupe_hash') !== false) {
                // Return null to say "not found", forcing insert or check
                return null;
            }
            if (strpos($query, 'SHOW TABLES') !== false) {
                return 'wp_ap_admin_notifications';
            }
            return null;
        }

        public function esc_like($text) {
            return addcslashes($text, '_%\\');
        }
    }
}

namespace {
    // Global mocks
    $wpdb = new \AperturePro\Helpers\MockWPDB();

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
        }
    }

    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            return $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient($transient) {
            // Return false to force DB check for table existence,
            // but we want table existence to pass so we hit the logic we want to test.
            if ($transient === 'ap_admin_queue_table_exists') return 1;
            return false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) {
            return true;
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook) { return true; }
    }

    require_once __DIR__ . '/../src/Helpers/Logger.php';
    require_once __DIR__ . '/../src/Email/EmailService.php';

    use AperturePro\Email\EmailService;

    echo "Benchmarking EmailService::enqueueAdminNotification...\n";

    $iterations = 50;
    $wpdb->query_count = 0;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        // Use same params to trigger dedupe logic repeatedly
        EmailService::enqueueAdminNotification('error', 'test_context', 'Something went wrong', ['i' => $i]);
    }

    $end = microtime(true);
    $duration = ($end - $start) * 1000;

    echo sprintf("Iterations: %d\n", $iterations);
    echo sprintf("Execution time: %.2f ms\n", $duration);
    echo sprintf("DB Query Count: %d\n", $wpdb->query_count);
}

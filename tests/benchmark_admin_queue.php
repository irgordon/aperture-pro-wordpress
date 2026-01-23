<?php

// Mock WordPress functions
namespace {
    $mock_options = [];
    $mock_transients = [];
    $mock_cron = [];

    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            global $mock_options;
            // Simulate reading from DB (deserialization)
            if (isset($mock_options[$option])) {
                return unserialize($mock_options[$option]);
            }
            return $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            global $mock_options;
            // Simulate writing to DB (serialization)
            $mock_options[$option] = serialize($value);
            return true;
        }
    }

    if (!function_exists('delete_option')) {
        function delete_option($option) {
            global $mock_options;
            unset($mock_options[$option]);
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
        function set_transient($transient, $value, $expiration = 0) {
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

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            global $mock_cron;
            return isset($mock_cron[$hook]);
        }
    }

    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
            global $mock_cron;
            $mock_cron[$hook] = ['timestamp' => $timestamp, 'recurrence' => $recurrence];
            return true;
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
        }
    }
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // No-op for benchmark
        }
    }
}

namespace {
    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        public $last_error = '';
        public $insert_count = 0;
        public $table_exists = false; // Toggle for benchmark

        public function prepare($query, ...$args) {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_var($query) {
            // Mock table existence check
            if (strpos($query, "SHOW TABLES LIKE") !== false) {
                if ($this->table_exists) {
                    return 'wp_ap_admin_notifications';
                }
                return null;
            }
            return null;
        }

        public function query($query) { return true; }
        public function esc_like($s) { return $s; }

        public function insert($table, $data, $format = null) {
            $this->insert_count++;
            return 1; // Success
        }
    }
    global $wpdb;
    $wpdb = new MockWPDB();

    // Load the class under test
    require_once __DIR__ . '/../src/Email/EmailService.php';
    use AperturePro\Email\EmailService;

    // --- BENCHMARK FUNCTION ---
    function run_benchmark($iterations, $useTable) {
        global $wpdb;
        $wpdb->table_exists = $useTable;
        $wpdb->insert_count = 0;

        // Reset option
        update_option('ap_admin_email_queue', []);

        // Reset static cache in EmailService
        try {
            $reflection = new ReflectionClass('AperturePro\Email\EmailService');
            $property = $reflection->getProperty('adminQueueTableExistsCache');
            $property->setAccessible(true);
            $property->setValue(null);
        } catch (ReflectionException $e) {
            // Ignore if property doesn't exist (e.g. legacy code)
        }

        echo "Running " . ($useTable ? "DB Table" : "Legacy Option") . " Benchmark ($iterations items)...\n";

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            EmailService::enqueueAdminNotification('info', 'benchmark', "Message $i", ['id' => $i]);
        }
        $end = microtime(true);
        $duration = $end - $start;

        echo "Time: " . number_format($duration, 4) . " s\n";

        if ($useTable) {
             echo "DB Inserts: " . $wpdb->insert_count . "\n";
        } else {
             $q = get_option('ap_admin_email_queue');
             echo "Option Count: " . count($q) . "\n";
        }
        echo "-------------------------------------------------------\n";
    }

    // Run baseline
    run_benchmark(1000, false);

    // Attempt run with table "enabled" (will fallback to legacy until code is implemented)
    run_benchmark(1000, true);
}

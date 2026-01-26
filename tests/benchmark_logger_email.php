<?php

namespace AperturePro\Helpers {
    // Mock WPDB Class
    class MockWPDB {
        public $prefix = 'wp_';

        public function insert($table, $data, $format = null) {
            return 1;
        }

        public function prepare($query, ...$args) {
            return $query;
        }

        public function get_var($query) {
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
            if ($option === 'admin_email') return 'admin@example.com';
            return $default;
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url($path = '') {
            return 'http://example.com/wp-admin/' . $path;
        }
    }

    // Crucial: Mock wp_mail with delay
    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
            // Simulate network latency or slow SMTP
            usleep(200000); // 200ms
            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            return false;
        }
    }

    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
            return true;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
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

    if (!function_exists('delete_transient')) {
        function delete_transient($transient) {
            return true;
        }
    }

    // Require the class under test
    require_once __DIR__ . '/../src/Helpers/Logger.php';
    require_once __DIR__ . '/../src/Email/EmailService.php';

    use AperturePro\Helpers\Logger;

    echo "Benchmarking Logger::log with critical error...\n";

    $start = microtime(true);

    // Trigger an error that causes an email
    // 'error' level and 'storage' context (one of the criticalContexts)
    Logger::log('error', 'storage', 'Disk full or something bad', ['some' => 'meta']);

    $end = microtime(true);
    $duration = ($end - $start) * 1000;

    echo sprintf("Execution time: %.2f ms\n", $duration);
}

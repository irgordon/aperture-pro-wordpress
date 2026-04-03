<?php

// Mock WordPress functions
namespace {
    $mock_options = [
        'admin_email' => 'admin@example.com',
        'ap_admin_email_last_sent' => []
    ];
    $get_option_calls = 0;

    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            global $mock_options, $get_option_calls;
            if ($option === 'admin_email') {
                $get_option_calls++;
            }
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

    if (!function_exists('delete_option')) {
        function delete_option($option) {
            global $mock_options;
            unset($mock_options[$option]);
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient($transient) { return false; }
    }

    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) { return true; }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient($transient) { return true; }
    }

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
            return false; // Fail to keep looping
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook) { return true; }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) { return json_encode($data); }
    }
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {}
    }
}

namespace {
    class MockWPDB {
        public $prefix = 'wp_';
        public function get_results($query, $output = 'OBJECT') {
            $items = [];
            for ($i = 0; $i < 50; $i++) {
                $items[] = [
                    'id' => $i,
                    'context' => 'context_' . $i,
                    'level' => 'info',
                    'message' => 'message ' . $i,
                    'meta' => json_encode(['id' => $i]),
                    'processed' => 0
                ];
            }
            return $items;
        }
        public function update($table, $data, $where) { return true; }
        public function insert($table, $data, $format = null) { return 1; }
        public function get_var($query) { return null; }
        public function prepare($query, ...$args) { return $query; }
        public function esc_like($s) { return $s; }
    }
    $GLOBALS['wpdb'] = new MockWPDB();

    require_once __DIR__ . '/../src/Email/EmailService.php';
    use AperturePro\Email\EmailService;

    function benchmark($name, $callback) {
        global $get_option_calls;
        $get_option_calls = 0;
        $start = microtime(true);
        $callback();
        $end = microtime(true);
        echo "$name took " . number_format(($end - $start) * 1000, 4) . " ms, get_option('admin_email') calls: $get_option_calls\n";
    }

    echo "Benchmarking processAdminQueue with 50 items (wp_mail fails to force looping)...\n";

    // To test legacy:
    benchmark("Legacy Processor", function() {
        $queue = [];
        for ($i = 0; $i < 50; $i++) {
            $queue[] = [
                'level' => 'info',
                'context' => 'context_' . $i,
                'message' => 'message ' . $i,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        update_option('ap_admin_email_queue', $queue);

        $reflection = new ReflectionClass('AperturePro\Email\EmailService');
        $prop = $reflection->getProperty('adminQueueTableExistsCache');
        $prop->setAccessible(true);
        $prop->setValue(false);

        EmailService::processAdminQueue();
    });

    // To test table-based:
    benchmark("Table-based Processor", function() {
        delete_option('ap_admin_email_queue');
        $reflection = new ReflectionClass('AperturePro\Email\EmailService');
        $prop = $reflection->getProperty('adminQueueTableExistsCache');
        $prop->setAccessible(true);
        $prop->setValue(true);

        EmailService::processAdminQueue();
    });
}

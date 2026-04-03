<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // No-op for benchmark
        }
    }
}

namespace {
    // Mock wpdb
    class wpdb {
        public $prefix = 'wp_';
        public $queries = [];
        public $insert_calls = 0;

        public function insert($table, $data) {
            $this->insert_calls++;
            // Simulate insert latency
            usleep(1000); // 1ms per insert
            return 1;
        }

        public function query($query) {
            $this->queries[] = $query;
            // Simulate query latency
            usleep(1000);
            return 1;
        }

        public function prepare($query, ...$args) {
            return $query;
        }

        public function get_var($query) {
            return null;
        }
    }

    global $wpdb;
    $wpdb = new wpdb();

    // Mock functions
    if (!function_exists('get_option')) {
        $mock_options = [];
        function get_option($option, $default = false) {
            global $mock_options;
            return $mock_options[$option] ?? $default;
        }
    }

    if (!function_exists('delete_option')) {
        function delete_option($option) {
            global $mock_options;
            unset($mock_options[$option]);
            return true;
        }
    }

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient($transient) { return false; }
    }
    if (!function_exists('set_transient')) {
        function set_transient($t, $v, $e) { return true; }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($h) { return false; }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($t, $h) { return true; }
    }
    if (!function_exists('register_shutdown_function')) {
        function register_shutdown_function($f) {}
    }

    // Load EmailService
    require_once __DIR__ . '/../src/Email/EmailService.php';

    use AperturePro\Email\EmailService;

    // Setup legacy queue
    $num_items = 100;
    $legacy_queue = [];
    for ($i = 0; $i < $num_items; $i++) {
        $legacy_queue[] = [
            'to' => "user{$i}@example.com",
            'subject' => "Subject $i",
            'body' => "Body $i",
            'headers' => ['X-Custom: ' . $i],
            'retries' => 0,
            'created_at' => current_time('mysql')
        ];
    }

    global $mock_options;
    $mock_options[EmailService::TRANSACTIONAL_QUEUE_OPTION] = $legacy_queue;

    echo "Benchmarking migrateLegacyQueue with $num_items items...\n";

    $start = microtime(true);
    // migrateLegacyQueue is private, so we need reflection or call it via processTransactionalQueue
    // but processTransactionalQueue has side effects.
    // Let's use reflection to test it directly.
    $class = new ReflectionClass(EmailService::class);
    $method = $class->getMethod('migrateLegacyQueue');
    $method->setAccessible(true);
    $method->invoke(null);
    $end = microtime(true);

    echo "Time taken: " . number_format($end - $start, 4) . "s\n";
    echo "wpdb->insert calls: " . $wpdb->insert_calls . "\n";
    echo "wpdb->query calls: " . count($wpdb->queries) . "\n";

    if (empty($mock_options[EmailService::TRANSACTIONAL_QUEUE_OPTION])) {
        echo "Queue successfully migrated and cleared.\n";
    } else {
        echo "FAILED: Queue not cleared.\n";
    }
}

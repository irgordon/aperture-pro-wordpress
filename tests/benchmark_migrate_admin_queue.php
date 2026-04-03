<?php

namespace {
    $mock_options = [];

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

    if (!function_exists('delete_option')) {
        function delete_option($option) {
            global $mock_options;
            unset($mock_options[$option]);
            return true;
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
        }
    }

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) { return true; }
    }

    if (!function_exists('get_transient')) {
        function get_transient($transient) { return false; }
    }

    class MockWPDB {
        public $prefix = 'wp_';
        public $insert_count = 0;
        public $query_count = 0;

        public function prepare($query, ...$args) {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function insert($table, $data, $format = null) {
            $this->insert_count++;
            usleep(500); // 0.5ms latency
            return 1;
        }

        public function query($query) {
            $this->query_count++;
            usleep(500); // 0.5ms latency
            return 1;
        }

        public function esc_like($s) { return $s; }

        public function get_var($query) {
            if (strpos($query, "SHOW TABLES LIKE") !== false) {
                return 'wp_ap_admin_notifications';
            }
            return null;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {}
    }
}

namespace {
    require_once __DIR__ . '/../src/Email/EmailService.php';
    use AperturePro\Email\EmailService;

    function run_migrate_admin_benchmark($count) {
        global $wpdb, $mock_options;

        // Setup legacy queue
        $queue = [];
        for ($i = 0; $i < $count; $i++) {
            $queue[] = [
                'level' => 'info',
                'context' => 'test',
                'message' => "Message $i",
                'meta' => ['id' => $i],
                'created_at' => current_time('mysql')
            ];
        }
        $mock_options[EmailService::ADMIN_QUEUE_OPTION] = $queue;
        $wpdb->insert_count = 0;
        $wpdb->query_count = 0;

        echo "Migrating $count items from Admin Queue...\n";
        $start = microtime(true);
        EmailService::migrateAdminQueue();
        $end = microtime(true);

        echo "Time: " . number_format($end - $start, 4) . "s\n";
        echo "Insert calls: " . $wpdb->insert_count . "\n";
        echo "Query calls: " . $wpdb->query_count . "\n";
        echo "---------------------------------\n";
        return $end - $start;
    }

    run_migrate_admin_benchmark(100);
    run_migrate_admin_benchmark(1000);
}

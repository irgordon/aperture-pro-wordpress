<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {
            // No-op for benchmark
        }
    }
}

namespace {
    // Mock WordPress functions
    if (!function_exists('get_option')) {
        $mock_options = [];
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

    if (!function_exists('get_transient')) {
        $mock_transients = [];
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

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
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

    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
            return true;
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
        }
    }

    if (!class_exists('MockWPDB')) {
        class MockWPDB {
            public $prefix = 'wp_';
            public $insert_calls = 0;
            public $query_calls = 0;

            public function insert($table, $data) {
                $this->insert_calls++;
                // Simulate some DB overhead
                usleep(500);
                return true;
            }

            public function query($query) {
                $this->query_calls++;
                // Simulate some DB overhead
                usleep(500);
                return true;
            }

            public function prepare($query, ...$args) {
                if (isset($args[0]) && is_array($args[0])) {
                    $args = $args[0];
                }
                // Very simple mock of prepare
                return "PREPARED: " . $query;
            }

            public function get_var($query) {
                if (strpos($query, "SHOW TABLES LIKE 'wp_ap_email_queue'") !== false) {
                    return 'wp_ap_email_queue';
                }
                if (strpos($query, "SHOW TABLES LIKE 'wp_ap_admin_notifications'") !== false) {
                    return 'wp_ap_admin_notifications';
                }
                // This handles the prepare result in adminQueueTableExists
                if (strpos($query, "PREPARED: SHOW TABLES LIKE") !== false) {
                     return 'wp_ap_admin_notifications';
                }
                return null;
            }

            public function get_results($query) {
                return [];
            }

            public function esc_like($text) {
                return $text;
            }
        }
    }
    $GLOBALS['wpdb'] = new MockWPDB();

    // Load EmailService
    require_once __DIR__ . '/../src/Email/EmailService.php';

    use AperturePro\Email\EmailService;

    function benchmark_migrate($method_name, $option_name, $num_items) {
        global $wpdb;
        $wpdb->insert_calls = 0;
        $wpdb->query_calls = 0;

        echo "Benchmarking $method_name with $num_items items...\n";

        // Setup legacy queue
        $queue = [];
        for ($i = 0; $i < $num_items; $i++) {
            if ($method_name === 'migrateLegacyQueue') {
                $queue[] = [
                    'to' => "user{$i}@example.com",
                    'subject' => "Subject $i",
                    'body' => "Body $i",
                    'headers' => ['X-Custom' => 'Value'],
                    'retries' => 0,
                    'created_at' => current_time('mysql'),
                ];
            } else {
                $queue[] = [
                    'level' => 'error',
                    'context' => 'test',
                    'message' => "Message $i",
                    'meta' => ['foo' => 'bar'],
                    'created_at' => current_time('mysql'),
                ];
            }
        }
        update_option($option_name, $queue);

        // Call method using reflection
        $reflection = new ReflectionClass(EmailService::class);
        $method = $reflection->getMethod($method_name);
        $method->setAccessible(true);

        $start = microtime(true);
        $method->invoke(null);
        $end = microtime(true);

        $time = $end - $start;

        echo "Time Taken: " . number_format($time, 4) . "s\n";
        echo "DB Insert Calls: " . $wpdb->insert_calls . "\n";
        echo "DB Query Calls: " . $wpdb->query_calls . "\n\n";
    }

    // Reset caches for the first run
    $reflection = new ReflectionClass(EmailService::class);
    $prop = $reflection->getProperty('_tableExists');
    $prop->setAccessible(true);
    $prop->setValue(null);

    $prop2 = $reflection->getProperty('adminQueueTableExistsCache');
    $prop2->setAccessible(true);
    $prop2->setValue(null);

    benchmark_migrate('migrateLegacyQueue', EmailService::TRANSACTIONAL_QUEUE_OPTION, 100);

    // Reset caches for the second run
    $prop->setValue(null);
    $prop2->setValue(null);

    benchmark_migrate('migrateAdminQueue', EmailService::ADMIN_QUEUE_OPTION, 100);
}

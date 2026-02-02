<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {}
    }
}

namespace {

    require_once __DIR__ . '/../src/Email/EmailService.php';
    use AperturePro\Email\EmailService;
    use ReflectionClass;
    use ReflectionMethod;

    // Mock WP Constants
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    // Mock WP Functions
    $mock_transients = [];

    if (!function_exists('get_transient')) {
        function get_transient($transient) {
            global $mock_transients;
            return isset($mock_transients[$transient]) ? $mock_transients[$transient] : false;
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

    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {}
    }

    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) { return false; }
    }

    if (!function_exists('get_option')) {
        function get_option($option, $default = false) { return $default; }
    }

    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) { return true; }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) { return json_encode($data); }
    }

    if (!function_exists('esc_like')) {
        function esc_like($text) { return addcslashes($text, '_%\\'); }
    }

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';

        public function prepare($query, ...$args) {
            // Simple mock prepare
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function esc_like($text) {
            return addcslashes($text, '_%\\');
        }

        public function get_var($query) {
            // Simulate DB Latency
            usleep(10000); // 10ms latency

            if (strpos($query, 'SHOW TABLES LIKE') !== false) {
                 // Simulate table exists
                 if (strpos($query, 'ap_email_queue') !== false) {
                    return 'wp_ap_email_queue';
                 }
                 // Handle escaped underscores if present
                 if (strpos($query, 'ap_admin_notifications') !== false || strpos($query, 'ap\\_admin\\_notifications') !== false) {
                    return 'wp_ap_admin_notifications';
                 }
            }
            return null;
        }

        public function query($query) {
            return true;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();


    // --- Benchmark Logic ---

    // Helper to reset static property
    function reset_static_cache() {
        $reflection = new ReflectionClass(EmailService::class);

        // Reset $_tableExists
        if ($reflection->hasProperty('_tableExists')) {
            $property = $reflection->getProperty('_tableExists');
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        // Reset $adminQueueTableExistsCache
        if ($reflection->hasProperty('adminQueueTableExistsCache')) {
            $property = $reflection->getProperty('adminQueueTableExistsCache');
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }

    echo "Benchmarking EmailService::tableExists()...\n";

    $iterations = 50;
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        // We intentionally reset the static cache to simulate new requests
        // or to force the code to rely on the persistent cache (transient)
        // instead of the static request-level cache.
        reset_static_cache();

        // We use reflection to call the protected method
        $method = new ReflectionMethod(EmailService::class, 'tableExists');
        $method->setAccessible(true);
        $exists = $method->invoke(null);

        if (!$exists) {
            echo "Error: Table should exist.\n";
        }
    }

    $end = microtime(true);
    $duration = $end - $start;

    echo "Total time for $iterations iterations: " . number_format($duration, 4) . "s\n";
    echo "Average time per iteration: " . number_format(($duration / $iterations) * 1000, 2) . "ms\n";

    // Also benchmark adminQueueTableExists for completeness
    echo "\nBenchmarking EmailService::adminQueueTableExists()...\n";
    $startAdmin = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        reset_static_cache();
        $method = new ReflectionMethod(EmailService::class, 'adminQueueTableExists');
        $method->setAccessible(true);
        $exists = $method->invoke(null);
        if (!$exists) { echo "Error: Admin table should exist.\n"; }
    }

    $endAdmin = microtime(true);
    $durationAdmin = $endAdmin - $startAdmin;
    echo "Total time for admin queue check: " . number_format($durationAdmin, 4) . "s\n";

}

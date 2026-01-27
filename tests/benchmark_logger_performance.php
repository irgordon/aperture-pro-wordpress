<?php

/**
 * Benchmark: Logger Performance
 *
 * GOAL:
 *  - Measure the overhead of Logger::log with and without buffering.
 */

namespace {
    // 1. Mock WordPress Environment and Classes if not already defined
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../');
    }

    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }

    // Mock WP functions if not present
    if (!function_exists('current_time')) { function current_time($type, $gmt = 0) { return date('Y-m-d H:i:s'); } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }

    // Mock WPDB
    class MockWPDBLogger {
        public $prefix = 'wp_';
        public $queries = [];
        public $insert_count = 0;
        public $query_count = 0;

        public function insert($table, $data, $format) {
            $this->insert_count++;
            return 1;
        }

        public function query($query) {
            $this->query_count++;
            return 1;
        }

        public function prepare($query, ...$args) {
            // Support passing an array as the first argument in args (WP style)
            if (isset($args[0]) && is_array($args[0])) {
                $args = $args[0];
            }

            foreach ($args as $arg) {
                // Very basic mock escaping
                $val = $arg;
                if (is_null($val)) $val = '';
                if (is_bool($val)) $val = $val ? '1' : '0';

                $query = preg_replace('/%s/', "'" . addslashes((string)$val) . "'", $query, 1);
            }
            return $query;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDBLogger();

    // Autoloader
    spl_autoload_register(function ($class) {
        if (strpos($class, 'AperturePro\\') === 0) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

namespace {
    use AperturePro\Helpers\Logger;

    echo "Starting Benchmark: Logger Performance...\n";

    $iterations = 1000;

    // Reset counters
    global $wpdb;
    $wpdb->insert_count = 0;
    $wpdb->query_count = 0;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        Logger::log('info', 'benchmark', 'Test log message ' . $i, ['i' => $i]);
    }

    // Explicitly flush remaining logs
    Logger::flush();

    $end = microtime(true);
    $duration = $end - $start;

    echo "Processed {$iterations} logs.\n";
    echo "Time: " . number_format($duration, 4) . "s\n";
    echo "DB Inserts (Single): " . $wpdb->insert_count . "\n";
    echo "DB Queries (Batch): " . $wpdb->query_count . "\n";
    echo "Total DB Interactions: " . ($wpdb->insert_count + $wpdb->query_count) . "\n";

    if ($wpdb->query_count > 0 && $wpdb->insert_count == 0) {
        echo "SUCCESS: Buffering is active.\n";
        $ratio = $iterations / ($wpdb->insert_count + $wpdb->query_count);
        echo "Batching Ratio: " . number_format($ratio, 1) . "x reduction in queries.\n";
    } else {
        echo "WARNING: Buffering NOT detected.\n";
    }
}

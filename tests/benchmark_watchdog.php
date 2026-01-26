<?php

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $data = []) {}
    }
}

namespace AperturePro\Email {
    class EmailService {
        public static function enqueueAdminNotification($level, $type, $subject, $data) {}
    }
}

namespace {
    /**
     * Benchmark for Watchdog N+1 Performance
     * Usage: php tests/benchmark_watchdog.php
     */

    $mock_transients = [];
    $query_count = 0;

    function wp_upload_dir() {
        return ['basedir' => sys_get_temp_dir() . '/ap_watchdog_bench'];
    }

    function trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }

    function get_transient($transient) {
        global $mock_transients, $query_count;
        $query_count++;
        // Simulate slight DB latency
        usleep(100);
        return $mock_transients[$transient] ?? false;
    }

    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }

    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }

    function current_time($type) {
        return date('Y-m-d H:i:s');
    }

    function sanitize_file_name($name) { return $name; }
    function wp_mkdir_p($path) { mkdir($path, 0777, true); }

    function maybe_unserialize($original) {
        if (is_serialized($original)) {
            return @unserialize($original);
        }
        return $original;
    }

    function is_serialized($data, $strict = true) {
        // Simple mock for basic serialization check
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' == $data) {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if (':' !== $data[1]) {
            return false;
        }
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) {
                return false;
            }
        }
        $token = $data[0];
        switch ($token) {
            case 's':
            case 'a':
            case 'O':
            case 'b':
            case 'd':
            case 'i':
                return true;
        }
        return false;
    }

    function wp_using_ext_object_cache() {
        return false;
    }

    // Mock WPDB for future batch implementation
    class MockWPDB {
        public $options = 'wp_options'; // Table name property used in query
        public $prefix = 'wp_';

        public function get_results($query, $output = 'OBJECT') {
            global $mock_transients, $query_count;
            $query_count++;
            // Simulate DB latency for one query
            usleep(500);

            $results = [];
            foreach ($mock_transients as $key => $value) {
                $row = new \stdClass();
                // Transient API stores data in `_transient_$key` or `_transient_timeout_$key`
                // But for get_results mock, we assume the code queries `option_name`
                $row->option_name = '_transient_' . $key; // Matches how transients are stored in options table
                $row->option_value = serialize($value);
                $results[] = $row;
            }

            return $results;
        }

        public function prepare($query, ...$args) {
            return $query;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();

    use AperturePro\Upload\Watchdog;
    use AperturePro\Upload\ChunkedUploadHandler;

    require_once __DIR__ . '/../src/Upload/ChunkedUploadHandler.php';
    require_once __DIR__ . '/../src/Upload/Watchdog.php';

    // Helper to setup test environment
    function setup_bench_env($count) {
        global $mock_transients;
        $mock_transients = [];

        $dir = sys_get_temp_dir() . '/ap_watchdog_bench';
        if (is_dir($dir)) {
            // clean up old
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
        } else {
            mkdir($dir, 0777, true);
        }

        $baseDir = $dir . '/aperture-uploads/';
        mkdir($baseDir, 0777, true);

        for ($i = 0; $i < $count; $i++) {
            $uploadId = 'bench_' . $i;
            $sessionDir = $baseDir . $uploadId . '/';
            mkdir($sessionDir, 0777, true);

            // Create a transient for this session
            // In WP, transient key is e.g. 'ap_upload_bench_0'
            $mock_transients[Watchdog::SESSION_TRANSIENT_PREFIX . $uploadId] = [
                'upload_id' => $uploadId,
                'created_at' => time(),
                'updated_at' => time(),
            ];
        }
    }

    $itemCount = 500;

    echo "Setting up environment with $itemCount sessions...\n";
    setup_bench_env($itemCount);

    echo "Running Watchdog benchmark...\n";

    $start = microtime(true);
    $query_count = 0;

    Watchdog::run();

    $end = microtime(true);
    $duration = $end - $start;

    echo "Time: " . number_format($duration, 4) . "s\n";
    echo "Queries (get_transient / DB calls): " . $query_count . "\n";

    // Cleanup
    $dir = sys_get_temp_dir() . '/ap_watchdog_bench';
     if (is_dir($dir)) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}

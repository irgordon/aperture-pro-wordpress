<?php
// tests/benchmark_select_batch.php

namespace {
    // 1. Mock Global WP Functions and Classes
    if (!defined('OBJECT')) define('OBJECT', 'OBJECT');

    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0) {
            return date('Y-m-d H:i:s');
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient($key) { return false; }
    }
    if (!function_exists('set_transient')) {
        function set_transient($key, $val, $exp) { return true; }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str) { return trim($str); }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) { return json_encode($data); }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request implements \ArrayAccess {
            public $params = [];
            public function __construct($params = []) { $this->params = $params; }
            public function get_param($key) { return $this->params[$key] ?? null; }
            public function offsetGet($offset) { return $this->params[$offset] ?? null; }
            public function offsetExists($offset) { return isset($this->params[$offset]); }
            public function offsetSet($offset, $value) { $this->params[$offset] = $value; }
            public function offsetUnset($offset) { unset($this->params[$offset]); }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response {
            public $data;
            public $status;
            public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $code;
            public $message;
            public $data;
            public function __construct($code, $message, $data = []) {
                $this->code = $code; $this->message = $message; $this->data = $data;
            }
        }
    }

    // 2. Mock DB
    class MockWPDB {
        public $prefix = 'wp_';
        public $last_error = '';
        public $query_log = [];
        public $update_count = 0;
        public $query_count = 0;

        public function update($table, $data, $where) {
            $this->update_count++;
            // Simulate DB latency
            usleep(100);
            return true;
        }

        public function query($query) {
            $this->query_count++;
            // Simulate DB latency (slightly more for a bigger query)
            usleep(200);
            return 100; // Return affected rows
        }

        public function prepare($query, ...$args) {
             // Basic replacement for simple testing
             // Note: real prepare is complex, this is just to avoid errors
            return $query;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $ctx, $msg, $meta = []) {}
    }
    class ErrorHandler {
        public static function traceId() { return 'trace_123'; }
    }
}

namespace AperturePro\Email {
    class EmailService {
        public static function enqueueAdminNotification($lvl, $ctx, $msg, $meta) {}
    }
}

namespace AperturePro\Storage {
    class StorageFactory {
        public static function create() {}
    }
}

namespace AperturePro\Proof {
    class ProofService {
        public static function getProofUrls($images, $storage) {}
    }
}

namespace AperturePro\Auth {
    class CookieService {
        public static function getClientSession() { return ['project_id' => 1, 'client_id' => 1]; }
    }
}

namespace AperturePro\Config {
    class Config {
        public static function get($key, $default = null) { return $default; }
    }
}

namespace AperturePro\Repositories {
    class ProjectRepository {
        public function get_images_for_project($id) { return []; }
    }
}

namespace {
    // 4. Load the Controller
    // We assume the controller files do NOT use block namespace syntax, so they might break this structure.
    // However, typical PSR files use `namespace X;` which works fine if included from global scope.
    // But inside `namespace { ... }` block, `require` will include the file.
    // If the included file starts with `namespace X;`, it terminates the current namespace block.
    // So we should NOT be inside a block when requiring them.
}

namespace {
    require_once __DIR__ . '/../src/REST/BaseController.php';
    require_once __DIR__ . '/../src/REST/ClientProofController.php';

    use AperturePro\REST\ClientProofController;

    // 5. Run Benchmark
    echo "Benchmarking select_batch...\n";

    $controller = new ClientProofController();
    $selections = [];
    // Generate 500 items
    for ($i = 1; $i <= 500; $i++) {
        $selections[] = ['image_id' => $i, 'selected' => ($i % 2 === 0)];
    }

    $request = new \WP_REST_Request();
    $request->params = [
        'gallery_id' => 123,
        'selections' => $selections
    ];

    $start = microtime(true);
    $response = $controller->select_batch($request);
    $end = microtime(true);

    echo "Time taken: " . number_format($end - $start, 4) . " seconds\n";
    echo "DB Update Calls: " . $wpdb->update_count . "\n";
    echo "DB Query Calls: " . $wpdb->query_count . "\n";

    if ($response instanceof \WP_Error) {
        echo "Error: " . $response->message . "\n";
    } else {
        echo "Response Status: " . $response->status . "\n";
    }
}

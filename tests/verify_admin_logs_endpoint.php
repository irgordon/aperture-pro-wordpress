<?php
/**
 * Endpoint simulation for AdminController::get_logs
 * This script is intended to be run by a runner that captures its output.
 */

// 1. Mock WordPress Environment
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

// Mock WP_Error
class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code, $message, $data = []) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

// Mock WP_REST_Request
class WP_REST_Request implements ArrayAccess {
    private $params = [];
    public function __construct($params = []) { $this->params = $params; }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function offsetExists($offset): bool { return isset($this->params[$offset]); }
    #[\ReturnTypeWillChange]
    public function offsetGet($offset) { return $this->params[$offset] ?? null; }
    public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->params[$offset]); }
}

// Mock WP_REST_Response
class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
}

// Mock WPDB
class MockWPDB {
    public $prefix = 'wp_';
    public function get_results($query) {
        // Return 5 sample rows
        $rows = [];
        for ($i=1; $i<=5; $i++) {
            $rows[] = (object) [
                'id' => $i,
                'level' => 'info',
                'context' => 'test',
                'message' => 'Log message ' . $i,
                'trace_id' => 'trace-' . $i,
                'meta' => ($i % 2 === 0) ? '{"foo":"bar","idx":'.$i.'}' : null,
                'created_at' => '2023-01-01 10:00:0'.$i,
            ];
        }
        return $rows;
    }
}

global $wpdb;
$wpdb = new MockWPDB();

// Mock global functions
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return '2023-10-27 10:00:00'; }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($ns, $route, $args) {}
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim($str); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('headers_sent')) {
    function headers_sent() { return false; }
}
if (!function_exists('header')) {
    function header($str) {
        // In simulation, we might output it or ignore.
        // Let's ignore to keep STDOUT clean for JSON, or output as comments?
        // But the runner expects JSON.
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return []; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $val, $exp) {}
}

// Autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'AperturePro\\') === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use AperturePro\REST\AdminController;

$controller = new AdminController();
$request = new WP_REST_Request(['limit' => 10]);

// This should output JSON and exit
$controller->get_logs($request);

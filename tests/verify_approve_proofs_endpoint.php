<?php
/**
 * Verification script for ClientProofController::approve_proofs
 * Usage: php tests/verify_approve_proofs_endpoint.php
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

    // ArrayAccess implementation
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
    public $last_update = null;
    public $last_error = '';

    public function update($table, $data, $where) {
        $this->last_update = [
            'table' => $table,
            'data' => $data,
            'where' => $where
        ];
        return 1; // Success
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
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) {}
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration) {}
}

// Autoloader for src classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'AperturePro\\') === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use AperturePro\REST\ClientProofController;

echo "Starting ClientProofController::approve_proofs verification...\n";

// Instantiate Controller
$controller = new ClientProofController();

// Test Case 1: Valid Approval
echo "\nTest 1: Valid approval request\n";
$galleryId = 123;
$request = new WP_REST_Request([
    'gallery_id' => $galleryId
]);

$response = $controller->approve_proofs($request);

if ($response instanceof WP_Error) {
    echo "[FAIL] Returned WP_Error: " . $response->message . "\n";
    exit(1);
}

if (!$wpdb->last_update) {
    echo "[FAIL] No DB update performed.\n";
    exit(1);
}

$expectedTable = $wpdb->prefix . 'ap_galleries';
if ($wpdb->last_update['table'] !== $expectedTable) {
    echo "[FAIL] Updated wrong table. Expected '$expectedTable', got: '" . $wpdb->last_update['table'] . "'\n";
    exit(1);
}

if ($wpdb->last_update['data']['status'] !== 'approved') {
    echo "[FAIL] Status not updated to 'approved'. Got: '" . $wpdb->last_update['data']['status'] . "'\n";
    exit(1);
}

if ($wpdb->last_update['where']['id'] !== $galleryId) {
    echo "[FAIL] Updated wrong gallery ID. Expected '$galleryId', got: '" . $wpdb->last_update['where']['id'] . "'\n";
    exit(1);
}

echo "[PASS] Valid approval updated status correctly.\n";


// Test Case 2: Invalid Gallery ID
echo "\nTest 2: Invalid Gallery ID\n";
$request = new WP_REST_Request([
    'gallery_id' => 0
]);

$response = $controller->approve_proofs($request);

if (!($response instanceof WP_Error)) {
    echo "[FAIL] Should have returned WP_Error for invalid ID.\n";
    exit(1);
}

if ($response->code !== 'invalid_gallery') {
    echo "[FAIL] Wrong error code. Expected 'invalid_gallery', got: '" . $response->code . "'\n";
    exit(1);
}

echo "[PASS] Invalid Gallery ID handled correctly.\n";

echo "\nVerification Completed Successfully.\n";

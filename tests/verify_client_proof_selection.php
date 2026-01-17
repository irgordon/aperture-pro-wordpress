<?php
/**
 * Verification script for ClientProofController::select_image
 * Usage: php tests/verify_client_proof_selection.php
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
    public $last_error = '';
    public $last_update = null;

    public function prepare($query, ...$args) { return $query; }
    public function query($query) { return true; }

    public function update($table, $data, $where) {
        $this->last_update = [
            'table' => $table,
            'data' => $data,
            'where' => $where
        ];
        return 1; // 1 row affected (success)
    }

    public function get_var($query) { return null; }
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

// Autoloader for src classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'AperturePro\\') === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Mock dependencies that Controller might use
// (BaseController, Logger, etc. are loaded via autoloader if they exist)
// We might need to mock Logger if it's not easily instantiable or writes to files we don't want.
// But let's try using the real classes first, if they don't have side effects.
// Looking at ClientProofController, it uses Logger. Let's see if we need to mock it.
// It calls Logger::log. If Logger writes to DB or file, we might want to mock it.
// But for this test, we care about select_image success path mainly.

use AperturePro\REST\ClientProofController;

echo "Starting ClientProofController::select_image verification...\n";

// Instantiate Controller
$controller = new ClientProofController();

// Test Case 1: Select Image (Success)
echo "\nTest 1: Select Image (Success)\n";
$request = new WP_REST_Request([
    'gallery_id' => 123,
    'image_id' => 456,
    'selected' => true
]);

$response = $controller->select_image($request);

if ($response instanceof WP_Error) {
    echo "[FAIL] Returned WP_Error: " . $response->message . "\n";
    exit(1);
}

if ($response->status !== 200) {
    echo "[FAIL] Unexpected status code: " . $response->status . "\n";
    exit(1);
}

// Check WPDB interaction
if (!$wpdb->last_update) {
    echo "[FAIL] No DB update performed.\n";
    exit(1);
}

if ($wpdb->last_update['table'] !== 'wp_ap_images') {
    echo "[FAIL] Incorrect table: " . $wpdb->last_update['table'] . "\n";
    exit(1);
}

if ($wpdb->last_update['data']['is_selected'] !== 1) {
    echo "[FAIL] is_selected not set to 1. Got: " . $wpdb->last_update['data']['is_selected'] . "\n";
    exit(1);
}

if ($wpdb->last_update['where']['id'] !== 456 || $wpdb->last_update['where']['gallery_id'] !== 123) {
    echo "[FAIL] Incorrect WHERE clause.\n";
    exit(1);
}

echo "[PASS] Image 456 selected in Gallery 123.\n";


// Test Case 2: Deselect Image (Success)
echo "\nTest 2: Deselect Image (Success)\n";
$request = new WP_REST_Request([
    'gallery_id' => 123,
    'image_id' => 456,
    'selected' => false
]);

$response = $controller->select_image($request);

if ($response instanceof WP_Error) {
    echo "[FAIL] Returned WP_Error: " . $response->message . "\n";
    exit(1);
}

if ($wpdb->last_update['data']['is_selected'] !== 0) {
    echo "[FAIL] is_selected not set to 0 for deselect. Got: " . $wpdb->last_update['data']['is_selected'] . "\n";
    exit(1);
}

echo "[PASS] Image 456 deselected.\n";

// Test Case 3: Invalid Parameters
echo "\nTest 3: Invalid Parameters\n";
$request = new WP_REST_Request([
    'gallery_id' => 0,
    'image_id' => 456,
    'selected' => true
]);

$response = $controller->select_image($request);

if (!($response instanceof WP_Error)) {
    echo "[FAIL] Did not return WP_Error for invalid gallery_id.\n";
    exit(1);
}

if ($response->code !== 'invalid_params') {
    echo "[FAIL] Incorrect error code: " . $response->code . "\n";
    exit(1);
}

echo "[PASS] Invalid parameters handled correctly.\n";

echo "\nVerification Completed Successfully.\n";

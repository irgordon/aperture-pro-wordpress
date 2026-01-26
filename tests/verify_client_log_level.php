<?php
/**
 * Verification script for ClientProofController::client_log normalization
 * Usage: php tests/verify_client_log_level.php
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
    public $last_insert = null;

    public function insert($table, $data, $format) {
        $this->last_insert = [
            'table' => $table,
            'data' => $data
        ];
        return 1;
    }

    // For Logger rate limiting check
    public function get_var($query) { return 0; }
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
    function get_option($key, $default = false) {
        if ($key === 'aperture_pro_settings') {
            return [
                'enable_client_logging' => true
            ];
        }
        return $default;
    }
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
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return false; }
}

// Mock CookieService since it uses $_COOKIE and base64/json
// We can just rely on the real class if we populate $_COOKIE, but mocking might be easier/safer if it has side effects (like setcookie).
// The real class calls setcookie which sends headers, which will fail in CLI without output buffering, but we are testing `client_log` which calls `getClientSession` (reads cookie).
// `getClientSession` reads $_COOKIE.

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
use AperturePro\Auth\CookieService;

echo "Starting ClientProofController::client_log verification...\n";

// Setup Cookie
$session = [
    'client_id' => 10,
    'project_id' => 20,
    'issued_at' => time()
];
$_COOKIE[CookieService::COOKIE_NAME] = base64_encode(json_encode($session));

// Instantiate Controller
$controller = new ClientProofController();

// Test Case 1: Level 'warn' should default to 'info' (normalization removed)
echo "\nTest 1: Level 'warn' normalization removal\n";
$request = new WP_REST_Request([
    'level' => 'warn',
    'message' => 'Something weird happened',
    'context' => 'js_client'
]);

$response = $controller->client_log($request);

if ($response instanceof WP_Error) {
    echo "[FAIL] Returned WP_Error: " . $response->message . "\n";
    exit(1);
}

if (!$wpdb->last_insert) {
    echo "[FAIL] No DB insert performed.\n";
    exit(1);
}

if ($wpdb->last_insert['data']['level'] !== 'info') {
    echo "[FAIL] Level not defaulted to info. Expected 'info', got: '" . $wpdb->last_insert['data']['level'] . "'\n";
    exit(1);
}

echo "[PASS] 'warn' defaulted to 'info'.\n";

// Test Case 2: Level 'warning' should stay 'warning'
echo "\nTest 2: Level 'warning' preservation\n";
$request = new WP_REST_Request([
    'level' => 'warning',
    'message' => 'Something warned',
    'context' => 'js_client'
]);

$controller->client_log($request);

if ($wpdb->last_insert['data']['level'] !== 'warning') {
    echo "[FAIL] Level changed unexpectedly. Expected 'warning', got: '" . $wpdb->last_insert['data']['level'] . "'\n";
    exit(1);
}

echo "[PASS] 'warning' preserved.\n";

// Test Case 3: Level 'error' should stay 'error'
echo "\nTest 3: Level 'error' preservation\n";
$request = new WP_REST_Request([
    'level' => 'error',
    'message' => 'Something failed',
    'context' => 'js_client'
]);

$controller->client_log($request);

if ($wpdb->last_insert['data']['level'] !== 'error') {
    echo "[FAIL] Level changed unexpectedly. Expected 'error', got: '" . $wpdb->last_insert['data']['level'] . "'\n";
    exit(1);
}

echo "[PASS] 'error' preserved.\n";

// Test Case 4: Invalid level should default to 'info'
echo "\nTest 4: Invalid level default\n";
$request = new WP_REST_Request([
    'level' => 'invalid_crazy_level',
    'message' => 'Crazy',
    'context' => 'js_client'
]);

$controller->client_log($request);

if ($wpdb->last_insert['data']['level'] !== 'info') {
    echo "[FAIL] Invalid level did not default to 'info'. Got: '" . $wpdb->last_insert['data']['level'] . "'\n";
    exit(1);
}

echo "[PASS] Invalid level defaulted to 'info'.\n";

echo "\nVerification Completed Successfully.\n";

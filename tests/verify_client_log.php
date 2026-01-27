<?php
/**
 * Verification script for ClientProofController::client_log
 * Usage: php tests/verify_client_log.php
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
    public $last_error = '';
    public $last_prepared_args = null;
    public $last_query = null;

    public function prepare($query, ...$args) {
        // Handle array arg (Logger batch)
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        $this->last_prepared_args = $args;
        return $query;
    }

    public function query($query) {
        $this->last_query = $query;
        return true;
    }

    public function insert($table, $data, $format = null) {
        $this->last_insert = [
            'table' => $table,
            'data' => $data
        ];
        return 1;
    }

    public function get_var($query) { return null; }
}

global $wpdb;
$wpdb = new MockWPDB();

// Mock Transients
global $mock_transients;
$mock_transients = [];

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
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'aperture_pro_settings') {
            return ['enable_client_logging' => 1];
        }
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $val) {}
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $mock_transients;
        return $mock_transients[$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $val, $exp) {
        global $mock_transients;
        $mock_transients[$key] = $val;
    }
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
use AperturePro\Helpers\Logger;

echo "Starting ClientProofController::client_log verification...\n";

// Setup Mock Session
$clientId = 999;
$projectId = 100;
$sessionPayload = [
    'client_id' => $clientId,
    'project_id' => $projectId
];
$_COOKIE['ap_client_session'] = base64_encode(json_encode($sessionPayload));

// Instantiate Controller
$controller = new ClientProofController();

// Test Case 1: Client Log (Success + Warn Mapping)
echo "\nTest 1: Client Log (Success + Warn Mapping)\n";
$request = new WP_REST_Request([
    'level' => 'warning',
    'context' => 'client_portal',
    'message' => 'Test log message',
    'meta' => ['foo' => 'bar']
]);

$response = $controller->client_log($request);

if ($response instanceof WP_Error) {
    echo "[FAIL] Returned WP_Error: " . $response->message . "\n";
    exit(1);
}

if ($response->status !== 200) {
    echo "[FAIL] Unexpected status code: " . $response->status . " (Expected 200). Error Code: " . ($response->data['error'] ?? 'unknown') . "\n";
    exit(1);
}

// Flush Logger Buffer
Logger::flush();

// Check WPDB interaction (via Logger)
// Logger::flush uses wpdb->query and wpdb->prepare.
if (!$wpdb->last_prepared_args) {
    echo "[FAIL] No DB insert performed (Logger failed).\n";
    exit(1);
}

// In batch insert, args are: level, context, message, trace, meta, created_at, level, context...
$level = $wpdb->last_prepared_args[0];

if ($level !== 'warning') {
    echo "[FAIL] Level not mapped to 'warning'. Got: " . $level . "\n";
    exit(1);
}

echo "[PASS] Client log recorded successfully with correct level mapping.\n";

// Test Case 2: Rate Limiting
echo "\nTest 2: Rate Limiting\n";

// Manually exhaust the limit
$window = floor(time() / 60);
$rateKey = 'ap_log_limit_' . $clientId . '_' . $window;
$mock_transients[$rateKey] = 60; // Set current count to limit

$request = new WP_REST_Request([
    'level' => 'info',
    'context' => 'client_portal',
    'message' => 'Spam message'
]);

$response = $controller->client_log($request);

if (!($response instanceof WP_REST_Response)) {
    // Controller might return WP_Error for failure if configured that way, but respond_error returns WP_REST_Response with error field usually?
    // checking BaseController::respond_error
    // It returns WP_REST_Response with success=false
}

if ($response->status !== 429) {
    echo "[FAIL] Expected 429 Too Many Requests. Got: " . $response->status . "\n";
    exit(1);
}

$errorData = $response->data ?? [];
if (($errorData['error'] ?? '') !== 'rate_limit_exceeded') {
     echo "[FAIL] Unexpected error code: " . ($errorData['error'] ?? 'none') . "\n";
     exit(1);
}

echo "[PASS] Rate limiting enforced correctly.\n";

echo "\nVerification Completed Successfully.\n";

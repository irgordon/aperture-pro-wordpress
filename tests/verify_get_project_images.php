<?php
/**
 * Verification script for ClientProofController::get_project_images
 * Usage: php tests/verify_get_project_images.php
 */

// 1. Mock WordPress Environment
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
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
    public $last_query = '';
    public $results_to_return = [];

    public function prepare($query, ...$args) {
        // Simple mock of prepare
        return vsprintf(str_replace('%d', '%s', $query), $args);
    }

    public function get_results($query, $output = OBJECT) {
        $this->last_query = $query;
        return $this->results_to_return;
    }

    // Stub other methods if needed
    public function get_row($query) { return null; }
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

use AperturePro\REST\ClientProofController;

echo "Starting ClientProofController::get_project_images verification...\n";

// Instantiate Controller
$controller = new ClientProofController();

// Use Reflection to access protected method
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('get_project_images');
$method->setAccessible(true);

// Set up Mock Data
$projectId = 123;
$wpdb->results_to_return = [
    [
        'id' => '101',
        'path' => 'projects/123/img1.jpg',
        'filename' => 'img1.jpg',
        'is_selected' => '0',
        'client_comments' => '[]'
    ],
    [
        'id' => '102',
        'path' => 'projects/123/img2.jpg',
        'filename' => 'img2.jpg',
        'is_selected' => '1',
        'client_comments' => 'json_encoded_array' // This might cause json_decode to fail if not valid json, checking handling.
    ]
];
// Fix the mock data for json
$wpdb->results_to_return[1]['client_comments'] = json_encode([['text' => 'Nice']]);


// Call the method
echo "Calling get_project_images($projectId)...\n";
$images = $method->invoke($controller, $projectId);

// Verify Interactions
echo "Verifying results...\n";

// Check if query contained project_id
if (strpos($wpdb->last_query, (string)$projectId) === false) {
    echo "[FAIL] Query did not contain project ID: " . $wpdb->last_query . "\n";
    exit(1);
}

// Check count
if (count($images) !== 2) {
    echo "[FAIL] Expected 2 images, got " . count($images) . "\n";
    exit(1);
}

// Check first image
$img1 = $images[0];
if ($img1['id'] !== 101) {
    echo "[FAIL] Image 1 ID mismatch. Got: " . $img1['id'] . "\n";
    exit(1);
}
if ($img1['project_id'] !== 123) {
    echo "[FAIL] Image 1 Project ID mismatch. Got: " . $img1['project_id'] . "\n";
    exit(1);
}
if ($img1['filename'] !== 'img1.jpg') {
    echo "[FAIL] Image 1 filename mismatch. Got: " . $img1['filename'] . "\n";
    exit(1);
}

// Check second image (selected + comments)
$img2 = $images[1];
if ($img2['is_selected'] !== true) {
    echo "[FAIL] Image 2 should be selected.\n";
    exit(1);
}
if (count($img2['comments']) !== 1 || $img2['comments'][0]['text'] !== 'Nice') {
    echo "[FAIL] Image 2 comments mismatch.\n";
    print_r($img2['comments']);
    exit(1);
}

echo "[PASS] get_project_images verification successful.\n";

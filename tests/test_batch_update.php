<?php

// Bootstrap a minimal WordPress environment for testing
define('ABSPATH', dirname(__DIR__) . '/');
define('WPINC', 'wp-includes');

// Mock for WP_REST_Request
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params = [];

        public function __construct($params = [])
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function __get($key)
        {
            return $this->get_param($key);
        }
    }
}

// Mock for WP_REST_Response
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;

        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}


// Mock the $wpdb global object
global $wpdb;
$wpdb = new class
{
    public $prefix = 'wp_';
    public $last_query;

    public function __construct()
    {
    }

    public function prepare($query, ...$args)
    {
        // A simple, unsafe sprintf-like replacement for testing purposes only.
        // In a real test suite, a more robust mock would be used.
        return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args);
    }

    public function query($query)
    {
        $this->last_query = $query;
        // Simulate that 2 rows were updated
        return 2;
    }
};

// Function to simulate current_time()
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return date('Y-m-d H:i:s');
    }
}
// Helper for logging
if (!class_exists('AperturePro\Helpers\Logger')) {
    // Create a dummy class to prevent fatal errors
    class_alias('AperturePro\Helpers\FakeLogger', 'AperturePro\Helpers\Logger');
}
namespace AperturePro\Helpers;
class FakeLogger
{
    public static function log($a, $b, $c, $d = [])
    {
        // Do nothing
    }
}

// Manually include the class file we are testing
require_once 'src/REST/BaseController.php';
require_once 'src/REST/ClientProofController.php';

use AperturePro\REST\ClientProofController;

// Test Execution
$test_name = "Test select_batch uses single optimized query";

$controller = new ClientProofController();

// Create a mock request with sample selections
$mock_request = new WP_REST_Request([
    'gallery_id' => 101,
    'selections' => [
        ['image_id' => 1, 'selected' => true],
        ['image_id' => 2, 'selected' => false],
        ['image_id' => 3, 'selected' => 1],
        ['image_id' => 0, 'selected' => true], // Invalid item
    ],
]);


// Call the method
$response = $controller->select_batch($mock_request);

// Assertions
$errors = [];

// 1. Check if the generated query is a single UPDATE statement
if (substr_count($wpdb->last_query, 'UPDATE') !== 1) {
    $errors[] = "Assertion failed: Expected 1 UPDATE statement, but found " . substr_count($wpdb->last_query, 'UPDATE') . ".";
}

// 2. Check for the CASE statement
if (strpos($wpdb->last_query, 'CASE id') === false) {
    $errors[] = "Assertion failed: The query does not contain the expected 'CASE id' statement.";
}

// 3. Check for the IN clause with the correct number of placeholders
if (substr_count($wpdb->last_query, 'IN (1, 2, 3)') === 0) {
    $errors[] = "Assertion failed: The query does not contain the expected 'IN (1, 2, 3)' clause.";
}

// 4. Check that the gallery_id is correctly applied in the WHERE clause
if (strpos($wpdb->last_query, 'gallery_id = 101') === false) {
    $errors[] = "Assertion failed: The WHERE clause does not filter by the correct gallery_id.";
}

// 5. Check the response data
if ($response->data['updated'] !== 2) {
    $errors[] = "Assertion failed: Response 'updated' count should be 2, but got " . $response->data['updated'] . ".";
}

if ($response->data['failed'] !== 1) {
    $errors[] = "Assertion failed: Response 'failed' count should be 1, but got " . $response->data['failed'] . ".";
}


// Output Results
if (empty($errors)) {
    echo "SUCCESS: " . $test_name . " passed.\n";
    echo "Generated Query: " . $wpdb->last_query . "\n";
    exit(0);
} else {
    echo "FAILURE: " . $test_name . " failed.\n";
    foreach ($errors as $error) {
        echo " - " . $error . "\n";
    }
    echo "Generated Query: " . $wpdb->last_query . "\n";
    exit(1);
}

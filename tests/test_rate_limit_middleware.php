<?php

// Mock WordPress functions and classes
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $mock_filters;
        $mock_filters[$tag][] = $callback;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

class WP_REST_Request {
    public function get_route() { return '/test/route'; }
    public function get_method() { return 'POST'; }
    public function get_param($key) { return null; }
}

class WP_REST_Response {
    public $headers = [];
    public function header($key, $value) {
        $this->headers[$key] = $value;
    }
}

class WP_Error {
    public function __construct($code = '', $message = '', $data = '') {}
}

// Mock Global State
$mock_filters = [];
$mock_options = [];

// Load classes
require_once __DIR__ . '/../src/REST/Middleware/MiddlewareInterface.php';
require_once __DIR__ . '/../src/Security/RateLimiter.php';
require_once __DIR__ . '/../src/Config/Config.php';
require_once __DIR__ . '/../src/Admin/AdminUI.php'; // For OPTION_KEY
require_once __DIR__ . '/../src/REST/Middleware/RateLimitMiddleware.php';

use AperturePro\REST\Middleware\RateLimitMiddleware;
use AperturePro\Security\RateLimiter;
use AperturePro\Config\Config;
use AperturePro\Admin\AdminUI;

// Mock RateLimiter
// We can't easily mock final classes without a mocking library or inheritance,
// but since RateLimiter depends on transients which we can mock, let's mock the transients
// OR simpler: we can just stub the RateLimiter class if it wasn't already loaded.
// Since it IS loaded above, we have to rely on its real behavior or stub `get_transient`/`set_transient`
// Let's stub transients.

$mock_transients = [];

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}

// Helper to run the middleware
function run_middleware_test($enable_headers) {
    global $mock_filters, $mock_options;
    $mock_filters = []; // Reset filters

    // Set config
    $mock_options[AdminUI::OPTION_KEY] = [
        'expose_rate_limit_headers' => $enable_headers
    ];

    // Force Config reload
    Config::set('dummy', 'val');

    $limiter = new RateLimiter();
    $middleware = new RateLimitMiddleware(
        $limiter,
        'test_action',
        10,
        60
    );

    $request = new WP_REST_Request();

    // Run handle
    $result = $middleware->handle($request);

    // If allowed, it returns null.
    // If headers are enabled, it should have added a 'rest_post_dispatch' filter.

    $filter_added = isset($mock_filters['rest_post_dispatch']);

    echo "Test with enable_headers=" . ($enable_headers ? 'true' : 'false') . ": ";
    if ($enable_headers === $filter_added) {
        echo "PASS (Filter " . ($filter_added ? "added" : "not added") . ")\n";
    } else {
        echo "FAIL (Expected " . ($enable_headers ? "added" : "not added") . ", got " . ($filter_added ? "added" : "not added") . ")\n";
        exit(1);
    }

    if ($filter_added) {
        // Test that the filter actually adds headers
        $callback = $mock_filters['rest_post_dispatch'][0];
        $response = new WP_REST_Response();
        $response = $callback($response);

        if (isset($response->headers['X-RateLimit-Limit'])) {
            echo "   -> Headers verified present in response.\n";
        } else {
            echo "   -> FAIL: Headers missing from response object.\n";
            exit(1);
        }
    }
}

echo "Running RateLimitMiddleware Tests...\n";
run_middleware_test(false);
run_middleware_test(true);

echo "\nAll tests passed.\n";

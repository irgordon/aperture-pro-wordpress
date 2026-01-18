<?php
// Mocks for WordPress functions and classes

class WP_Error {
    public function __construct(
        public string $code = '',
        public string $message = '',
        public array $data = []
    ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

class WP_REST_Response {
    public function __construct(
        public $data = null,
        public int $status = 200
    ) {}
    public function header($key, $value) { /* mock */ }
}

class WP_REST_Request {
    public function __construct(
        private string $method = 'GET',
        private string $route = '/test',
        private string $body = '',
        private array $params = []
    ) {}
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_body() { return $this->body; }
    public function get_params() { return $this->params; }
    public function get_param($key) { return $this->params[$key] ?? null; }
}

$mock_transients = [];

function get_transient($key) {
    global $mock_transients;
    $val = $mock_transients[$key] ?? false;
    // error_log("get_transient($key) -> " . json_encode($val));
    return $val;
}

function set_transient($key, $value, $expiration) {
    global $mock_transients;
    $mock_transients[$key] = $value;
    // error_log("set_transient($key, " . json_encode($value) . ", $expiration)");
    return true;
}

function sanitize_email($email) {
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    // mock
}

function wp_json_encode($data) {
    return json_encode($data);
}

function current_time($type) {
    return date('Y-m-d H:i:s'); // simplified
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'AperturePro\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use AperturePro\Security\RateLimiter;
use AperturePro\REST\Middleware\RateLimitMiddleware;
use AperturePro\REST\Middleware\RequestHygieneMiddleware;
use AperturePro\REST\Middleware\MiddlewareStack;

// --- TESTS ---

echo "Starting Tests...\n";

// 1. Test RateLimiter
$limiter = new RateLimiter();
$key = 'test_key';
$limit = 2;
$window = 100;

// Attempt 1: Should pass
$res = $limiter->attempt($key, $limit, $window);
assert($res['allowed'] === true, 'Attempt 1 should be allowed');
assert($res['remaining'] === 1, 'Remaining should be 1');

// Attempt 2: Should pass
$res = $limiter->attempt($key, $limit, $window);
assert($res['allowed'] === true, 'Attempt 2 should be allowed');
assert($res['remaining'] === 0, 'Remaining should be 0');

// Attempt 3: Should fail
$res = $limiter->attempt($key, $limit, $window);
assert($res['allowed'] === false, 'Attempt 3 should be denied');

echo "RateLimiter Tests Passed.\n";

// 2. Test RequestHygieneMiddleware
$hygiene = new RequestHygieneMiddleware(100); // 100 bytes limit
$req = new WP_REST_Request('POST', '/test', 'small payload');
$res = $hygiene->handle($req);
assert($res === null, 'Small payload should pass');

$req = new WP_REST_Request('POST', '/test', str_repeat('a', 101));
$res = $hygiene->handle($req);
assert($res instanceof WP_Error, 'Large payload should return WP_Error');
assert($res->get_error_code() === 'ap_payload_too_large', 'Error code mismatch');

$req = new WP_REST_Request('POST', '/test', 'union select * from users');
$res = $hygiene->handle($req);
assert($res instanceof WP_Error, 'Suspicious payload should return WP_Error');
assert($res->get_error_code() === 'ap_suspicious_request', 'Error code mismatch');

echo "RequestHygieneMiddleware Tests Passed.\n";

// 3. Test MiddlewareStack and RateLimitMiddleware
// Reset transients for a new test
$mock_transients = [];

$stack = new MiddlewareStack([
    new RateLimitMiddleware(new RateLimiter(), 'api_action', 1, 60, 'ip')
]);

$req = new WP_REST_Request('GET', '/api');

// First call: allowed
$res = $stack->run($req);
assert($res === null, 'First stack call should pass');

// Second call: blocked (limit 1)
$res = $stack->run($req);
assert($res instanceof WP_Error, 'Second stack call should be blocked');
assert($res->get_error_code() === 'ap_rate_limited', 'Error code mismatch for rate limit');

echo "MiddlewareStack & RateLimitMiddleware Tests Passed.\n";

echo "All tests passed successfully.\n";

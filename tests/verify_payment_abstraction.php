<?php

// Define constants
if (!defined('ABSPATH')) define('ABSPATH', '/var/www/html/');

// Mock WordPress functions
function register_rest_route($namespace, $route, $args) {
    global $routes;
    $routes[$route] = $args;
}
function current_user_can($cap) { return true; }
function current_time($type) { return date('Y-m-d H:i:s'); }
function wp_json_encode($data) { return json_encode($data); }
function home_url($path) { return 'http://example.com' . $path; }
function admin_url($path) { return 'http://example.com/wp-admin/' . $path; }
function add_query_arg($key, $val, $url) { return $url . "?$key=$val"; }
function set_transient($key, $value, $expiration) {}
function get_transient($key) { return false; }
function get_option($key) { return 'admin@example.com'; }
function wp_mail($to, $subject, $body) { return true; }

// Mock WP Classes
class WP_REST_Request implements ArrayAccess {
    public $params = [];
    public $body = '';
    public $headers = [];
    public $method = 'GET';
    public $route = '';

    public function __construct($method, $route) {
        $this->method = $method;
        $this->route = $route;
    }
    public function set_param($key, $value) { $this->params[$key] = $value; }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function offsetGet($offset) { return $this->params[$offset] ?? null; }
    public function offsetExists($offset) { return isset($this->params[$offset]); }
    public function offsetSet($offset, $value) { $this->params[$offset] = $value; }
    public function offsetUnset($offset) { unset($this->params[$offset]); }
    public function set_body($body) { $this->body = $body; }
    public function get_body() { return $this->body; }
    public function get_headers() { return $this->headers; }
    public function set_header($key, $value) { $this->headers[$key] = [$value]; }
}

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}

// Mock WPDB
global $wpdb;
$wpdb = new class {
    public $prefix = 'wp_';
    public $projects = [];
    public $events = [];
    public $galleries = [];

    public function __construct() {
        // Seed
        $this->projects[1] = [
            'id' => 1,
            'package_price' => 100.00,
            'client_email' => 'test@example.com',
            'payment_provider' => 'stripe',
            'payment_status' => 'pending',
            'payment_amount_received' => 0,
            'payment_currency' => 'USD',
            'payment_intent_id' => null,
            'payment_last_update' => null,
            'updated_at' => null,
            'booking_date' => '2023-01-01',
            'title' => 'Test Project'
        ];
        $this->galleries[] = ['id' => 10, 'project_id' => 1, 'type' => 'final'];
    }

    public function prepare($query, ...$args) {
        return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args);
    }

    public function get_row($query) {
        if (strpos($query, 'ap_projects') !== false) {
             foreach ($this->projects as $p) {
                 if (strpos($query, "id = {$p['id']}") !== false || strpos($query, "payment_intent_id = '{$p['payment_intent_id']}'") !== false) {
                     return (object)$p;
                 }
             }
        }
        return null;
    }

    public function get_var($query) {
        return 10; // gallery id
    }

    public function get_results($query) { return []; }

    public function update($table, $data, $where) {
        if (strpos($table, 'ap_projects') !== false) {
            $id = $where['id'];
            if (isset($this->projects[$id])) {
                $this->projects[$id] = array_merge($this->projects[$id], $data);
                return true;
            }
        }
        return false;
    }

    public function insert($table, $data, $format=null) {
        if (strpos($table, 'ap_payment_events') !== false) {
            $this->events[] = $data;
        }
        return true;
    }
};

// Mock Dependencies via class_alias
class MockEmailService {
    public static function sendTemplate($template, $to, $vars) { echo "Email sent to $to for $template\n"; }
    public static function enqueueAdminNotification($level, $context, $message, $meta) { echo "Admin notification enqueued: $message\n"; }
}
class_alias('MockEmailService', 'AperturePro\Email\EmailService');

class MockErrorHandler {
    public static function traceId() { return 'trace-123'; }
}
class_alias('MockErrorHandler', 'AperturePro\Helpers\ErrorHandler');

class MockWorkflow {
    public static function onPaymentReceived($id) { echo "Workflow triggered for project $id\n"; }
}
class_alias('MockWorkflow', 'AperturePro\Workflow\Workflow');

// Load Source Files
require_once 'src/Payments/PaymentProviderInterface.php';
require_once 'src/Payments/DTO/PaymentIntentResult.php';
require_once 'src/Payments/DTO/WebhookEvent.php';
require_once 'src/Payments/DTO/PaymentUpdate.php';
require_once 'src/Payments/DTO/RefundResult.php';
require_once 'src/Payments/DTO/ProviderCapabilities.php';
require_once 'src/Payments/PaymentProviderFactory.php';
require_once 'src/Payments/Providers/StripeProvider.php';
require_once 'src/Payments/Providers/PayPalProvider.php';
require_once 'src/Payments/Providers/SquareProvider.php';
require_once 'src/Payments/Providers/AuthorizeNetProvider.php';
require_once 'src/Payments/Providers/AmazonPayProvider.php';

require_once 'src/Helpers/Logger.php';
require_once 'src/REST/BaseController.php';
require_once 'src/Services/PaymentService.php';
require_once 'src/REST/PaymentController.php';

use AperturePro\REST\PaymentController;

// --- TEST EXECUTION ---
echo "Starting Payment Abstraction Tests...\n";

$controller = new PaymentController();

// 1. Test Retry Payment (Simulate button click in Admin UI)
echo "\n--- Test 1: Retry Payment (Create Intent) ---\n";
$req1 = new WP_REST_Request('POST', '/projects/1/retry-payment');
$req1->set_param('id', 1);
$res1 = $controller->retry_payment($req1);

if ($res1->status === 200 && isset($res1->data['payment_intent'])) {
    echo "SUCCESS: Payment Intent Created: " . $res1->data['payment_intent'] . "\n";
    $intentId = $res1->data['payment_intent'];

    // Verify DB
    global $wpdb;
    if ($wpdb->projects[1]['payment_intent_id'] === $intentId) {
        echo "SUCCESS: DB Updated with Intent ID\n";
    } else {
        echo "FAILURE: DB not updated. Got: " . $wpdb->projects[1]['payment_intent_id'] . "\n";
        exit(1);
    }
} else {
    echo "FAILURE: Retry Payment failed.\n";
    print_r($res1);
    exit(1);
}

// 2. Test Webhook (Stripe Succeeded)
echo "\n--- Test 2: Handle Webhook (Stripe Succeeded) ---\n";
// Construct payload
$payload = json_encode([
    'type' => 'payment_intent.succeeded',
    'data' => [
        'object' => [
            'id' => $intentId,
            'amount_received' => 10000,
            'currency' => 'usd',
            'metadata' => ['project_id' => 1]
        ]
    ]
]);

$req2 = new WP_REST_Request('POST', '/webhooks/payment/stripe');
$req2->set_param('provider', 'stripe');
$req2->set_body($payload);
$req2->set_header('Content-Type', 'application/json');

$res2 = $controller->handle_webhook($req2);

if ($res2->status === 200 && $res2->data['success']) {
    echo "SUCCESS: Webhook processed.\n";

    $project = $wpdb->projects[1];
    if ($project['payment_status'] === 'paid') {
        echo "SUCCESS: Project status is PAID\n";
    } else {
        echo "FAILURE: Project status is " . $project['payment_status'] . "\n";
        exit(1);
    }

    if ($project['payment_amount_received'] == 100.0) {
        echo "SUCCESS: Amount received is 100.0\n";
    } else {
        echo "FAILURE: Amount received is " . $project['payment_amount_received'] . "\n";
        exit(1);
    }
} else {
    echo "FAILURE: Webhook processing failed.\n";
    print_r($res2);
    exit(1);
}

// 3. Test Provider Factory with invalid provider
echo "\n--- Test 3: Invalid Provider ---\n";
$req3 = new WP_REST_Request('POST', '/webhooks/payment/invalid');
$req3->set_param('provider', 'invalid');
$req3->set_body('{}');

$res3 = $controller->handle_webhook($req3);

if ($res3->status === 400 && strpos($res3->data['message'], 'Unknown provider') !== false) {
    echo "SUCCESS: Correctly handled unknown provider.\n";
} else {
    echo "FAILURE: Did not handle unknown provider correctly. Status: " . $res3->status . "\n";
    print_r($res3->data);
    exit(1);
}

// 4. Test PayPal Provider (Checkout URL generation)
echo "\n--- Test 4: PayPal Provider (Checkout URL) ---\n";
// Update project to use paypal
global $wpdb;
$wpdb->projects[1]['payment_provider'] = 'paypal';

$req4 = new WP_REST_Request('POST', '/projects/1/retry-payment');
$req4->set_param('id', 1);
$res4 = $controller->retry_payment($req4);

if ($res4->status === 200 && isset($res4->data['checkout_url'])) {
    if (strpos($res4->data['checkout_url'], 'paypal.com') !== false) {
         echo "SUCCESS: PayPal checkout URL generated: " . $res4->data['checkout_url'] . "\n";
    } else {
         echo "FAILURE: Incorrect PayPal URL: " . $res4->data['checkout_url'] . "\n";
         exit(1);
    }
} else {
    echo "FAILURE: PayPal retry failed.\n";
    print_r($res4);
    exit(1);
}

echo "\nALL TESTS PASSED.\n";

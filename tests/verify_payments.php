<?php

namespace {
    // Mock WordPress functions
    if (!function_exists('current_time')) {
        function current_time($type) { return date('Y-m-d H:i:s'); }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) { return json_encode($data); }
    }
    if (!function_exists('get_option')) {
        function get_option($key, $default = []) { return $default; }
    }
    if (!function_exists('update_option')) {
        function update_option($key, $value) {}
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str) { return trim($str); }
    }
    if (!function_exists('sanitize_email')) {
        function sanitize_email($email) { return $email; }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) { return $url; }
    }
    if (!function_exists('plugin_dir_path')) {
        function plugin_dir_path($file) { return __DIR__ . '/../'; }
    }
    if (!function_exists('plugin_dir_url')) {
        function plugin_dir_url($file) { return 'http://localhost/wp-content/plugins/aperture-pro/'; }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg($key, $val, $url) { return $url . "?$key=$val"; }
    }
    if (!function_exists('home_url')) {
        function home_url($path = '/') { return 'http://localhost' . $path; }
    }
    if (!function_exists('set_transient')) {
        function set_transient($key, $val, $exp) {}
    }

    // Mock Global $wpdb
    class MockWPDB {
        public $prefix = 'wp_';
        public function prepare($query, ...$args) { return $query; }
        public function get_row($query) {
            // Return a mock project
            return (object)[
                'id' => 123,
                'package_price' => 100,
                'payment_provider' => 'stripe',
                'payment_status' => 'pending',
                'payment_amount' => 0,
                'payment_currency' => 'USD',
                'payment_intent_id' => 'pi_mock',
                'payment_last_update' => '',
                'booking_date' => '',
                'title' => 'Test Project'
            ];
        }
        public function update($table, $data, $where) { echo "DB Update: " . json_encode($data) . "\n"; }
        public function insert($table, $data) { echo "DB Insert: " . json_encode($data) . "\n"; return true; }
        public function get_var($query) { return 1; } // Gallery ID
        public function get_results($query) { return []; }
    }
    global $wpdb;
    $wpdb = new MockWPDB();
}

// Mock Stripe SDK
namespace Stripe {
    class StripeClient {
        public $paymentIntents;
        public $refunds;
        public function __construct($key) {
            $this->paymentIntents = new class {
                public function create($args) {
                return new class($args) {
                    public $id = 'pi_test_123';
                    public $amount;
                    public $currency;
                    public $client_secret = 'cs_test_123';
                    public function __construct($args) {
                        $this->amount = $args['amount'];
                        $this->currency = $args['currency'];
                    }
                    public function toArray() { return ['id' => $this->id]; }
                };
                }
            };
            $this->refunds = new class {
                public function create($args) {
                return new class($args) {
                    public $id = 're_test_123';
                    public $amount = 100;
                    public $currency = 'usd';
                    public function toArray() { return ['id' => $this->id]; }
                };
                }
            };
        }
    }
    class Webhook {
        public static function constructEvent($payload, $sig, $secret) {
            return (object)[
                'type' => 'payment_intent.succeeded',
                'data' => (object)[
                    'object' => (object)[
                        'id' => 'pi_test_123',
                        'metadata' => ['project_id' => 123],
                        'amount_received' => 10000,
                        'currency' => 'usd',
                        'toArray' => function() { return ['id'=>'pi_test_123']; }
                    ]
                ]
            ];
        }
    }
}

// Mock PayPal SDK
namespace PayPalCheckoutSdk\Core {
    class PayPalHttpClient {
        public function __construct($env) {}
        public function execute($req) {
            // Simple mock response
            return (object)[
                'result' => (object)[
                    'id' => 'ORDER_ID',
                    'amount' => (object)['value' => '100.00', 'currency_code' => 'USD'],
                    'status' => 'APPROVED',
                    'links' => [(object)['rel' => 'approve', 'href' => 'https://paypal.com/checkout']],
                    'toArray' => function() { return ['id'=>'ORDER_ID']; }
                ]
            ];
        }
    }
    class SandboxEnvironment { public function __construct($id, $secret){} }
    class ProductionEnvironment { public function __construct($id, $secret){} }
}
namespace PayPalCheckoutSdk\Orders {
    class OrdersCreateRequest { public $body; public function prefer($p){} }
    class OrdersCaptureRequest {}
}
namespace PayPalCheckoutSdk\Payments {
    class CapturesRefundRequest { public $body; public function __construct($id){} }
}

// End Mocks
namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $type, $msg, $ctx=[]) { echo "Log [$level]: $msg\n"; }
    }
}

namespace AperturePro\Email {
    class EmailService {
        public static function sendTemplate($tpl, $to, $data) { echo "Email sent to $to\n"; }
    }
}

namespace AperturePro\Workflow {
    class Workflow {
        public static function onPaymentReceived($id) { echo "Workflow triggered for $id\n"; }
    }
}

namespace AperturePro\Admin {
    class AdminUI { const OPTION_KEY = 'aperture_pro_settings'; }
}

namespace AperturePro\Tests {
    require_once __DIR__ . '/../src/Payments/PaymentProviderInterface.php';
    require_once __DIR__ . '/../src/Payments/DTO/PaymentIntentResult.php';
    require_once __DIR__ . '/../src/Payments/DTO/PaymentUpdate.php';
    require_once __DIR__ . '/../src/Payments/DTO/WebhookEvent.php';
    require_once __DIR__ . '/../src/Payments/DTO/RefundResult.php';
    require_once __DIR__ . '/../src/Payments/Providers/StripeProvider.php';
    require_once __DIR__ . '/../src/Payments/Providers/PayPalProvider.php';
    require_once __DIR__ . '/../src/Payments/PaymentProviderFactory.php';
    require_once __DIR__ . '/../src/Repositories/ProjectRepository.php';
    require_once __DIR__ . '/../src/Services/WorkflowAdapter.php';
    require_once __DIR__ . '/../src/Services/PaymentService.php';
    require_once __DIR__ . '/../src/Config/Config.php';
    require_once __DIR__ . '/../src/Config/Settings.php';

    // Mock Config
    \AperturePro\Config\Config::set('stripe_secret_key', 'sk_test');
    \AperturePro\Config\Config::set('stripe_webhook_secret', 'whsec_test');
}

namespace {
    if (!function_exists('aperture_pro')) {
        function aperture_pro() {
             return new class {
                public $settings;
                public function __construct() {
                    $this->settings = new \AperturePro\Config\Settings();
                }
            };
        }
    }

    // Run Tests
    echo "Starting PaymentService Test...\n";

    $repo = new \AperturePro\Repositories\ProjectRepository();
    $workflow = new \AperturePro\Services\WorkflowAdapter();
    $service = new \AperturePro\Services\PaymentService($repo, $workflow);

    // Test Create Intent (Stripe)
    echo "Testing Create Intent (Stripe)...\n";
    try {
        $intent = $service->create_intent_for_project(123);
        echo "Intent ID: " . $intent->id . "\n";
        if ($intent->id === 'pi_test_123') echo "PASS: Intent Created\n"; else echo "FAIL: Intent ID mismatch\n";
    } catch (\Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString();
    }

    // Test Apply Update
    echo "Testing Apply Update...\n";
    $update = new \AperturePro\Payments\DTO\PaymentUpdate(
        project_id: 123,
        status: 'paid',
        payment_intent_id: 'pi_test_123',
        amount: 100.00,
        currency: 'USD',
        raw_event: []
    );
    try {
        $service->apply_update($update);
        echo "PASS: Update applied (Check DB output above).\n";
    } catch (\Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }

    // Test Refund
    echo "Testing Refund...\n";
    try {
        $refund = $service->refund(123, 5000);
        echo "Refund ID: " . $refund->refund_id . "\n";
        if ($refund->refund_id === 're_test_123') echo "PASS: Refund Created\n"; else echo "FAIL: Refund ID mismatch\n";
    } catch (\Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

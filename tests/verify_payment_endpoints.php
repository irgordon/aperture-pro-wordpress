<?php
namespace AperturePro\Helpers {
    class Logger { public static function log($l, $c, $m, $meta=[]) {} }
    class Crypto { public static function decrypt($s) { return $s; } }
}
namespace AperturePro\Email {
    class EmailService { public static function sendTemplate($t, $to, $d) {} }
}
namespace AperturePro\Storage {
    class StorageFactory {}
}
namespace AperturePro\Config {
    class Config { public static function all() { return []; } }
}

namespace {
    // Global mocks
    class MockWPDB {
        public $prefix = 'wp_';
        public function prepare($query, ...$args) { return $query; }
        public function get_row($query) {
             if (strpos($query, 'ap_projects') !== false) {
                 if (strpos($query, 'id = 0') !== false) return null;
                 $project = new stdClass();
                 $project->id = 123;
                 $project->package_price = 200.00;
                 $project->payment_status = 'pending';
                 $project->payment_amount = 0;
                 $project->payment_currency = 'USD';
                 $project->payment_provider = 'stripe';
                 $project->payment_intent_id = 'pi_123';
                 $project->payment_last_update = '2026-01-01';
                 $project->booking_date = '2026-01-01';
                 return $project;
             }
             return null;
        }
        public function get_results($query) { return []; }
        public function get_var($query) { return 456; }
        public function update($table, $data, $where) { return 1; }
        public function insert($table, $data) { return 1; }
    }

    global $wpdb;
    $wpdb = new MockWPDB();

    // Mock WP functions
    function current_user_can($cap) { return true; }

    // Mock SDKs
    class MockStripeClient {
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
        }
    }
    class_alias('MockStripeClient', 'Stripe\StripeClient');

    function aperture_pro() {
        return new class {
            public $settings;
            public function __construct() {
                $this->settings = new class {
                    public function get($key) {
                        if ($key === 'stripe') return ['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test'];
                        if ($key === 'paypal') return ['client_id' => 'sb_client', 'secret' => 'sb_secret', 'sandbox' => true];
                        return null;
                    }
                };
            }
        };
    }

    function register_rest_route($ns, $route, $args) {
        global $routes;
        $routes[$route] = $args;
    }

    class WP_REST_Request implements ArrayAccess {
        private $params = [];
        public function __construct($params = []) { $this->params = $params; }
        public function offsetExists($offset): bool { return isset($this->params[$offset]); }
        public function offsetGet($offset): mixed { return $this->params[$offset] ?? null; }
        public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
        public function offsetUnset($offset): void { unset($this->params[$offset]); }
    }

    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data, $status) {
            $this->data = $data;
            $this->status = $status;
        }
    }

    function current_time($type) { return '2026-01-17 12:00:00'; }
    function home_url($path) { return "http://example.com" . $path; }
    function wp_json_encode($data) { return json_encode($data); }

    // Required classes
    require_once __DIR__ . '/../src/Payments/DTO/PaymentIntentResult.php';
    require_once __DIR__ . '/../src/Payments/DTO/PaymentUpdate.php';
    require_once __DIR__ . '/../src/Payments/DTO/WebhookEvent.php';
    require_once __DIR__ . '/../src/Payments/DTO/RefundResult.php';
    require_once __DIR__ . '/../src/Repositories/ProjectRepository.php';
    require_once __DIR__ . '/../src/Services/WorkflowAdapter.php';
    require_once __DIR__ . '/../src/Payments/PaymentProviderInterface.php';
    require_once __DIR__ . '/../src/Payments/PaymentProviderFactory.php';
    require_once __DIR__ . '/../src/Payments/Providers/StripeProvider.php';
    require_once __DIR__ . '/../src/REST/BaseController.php';
    require_once __DIR__ . '/../src/Services/PaymentService.php'; // The real service
    require_once __DIR__ . '/../src/REST/PaymentController.php';

    use AperturePro\REST\PaymentController;

    echo "Initializing PaymentController...\n";
    $controller = new PaymentController();
    $controller->register_routes();

    echo "Testing get_payment_summary...\n";
    $request = new WP_REST_Request(['id' => 123]);
    $response = $controller->get_payment_summary($request);
    print_r($response);

    echo "Testing retry_payment...\n";
    $response = $controller->retry_payment($request);
    print_r($response);
}

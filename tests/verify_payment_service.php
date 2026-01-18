<?php
namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $meta = []) {
            echo "Log [$level] $context: $message\n";
        }
    }
}

namespace AperturePro\Email {
    class EmailService {
        public static function sendTemplate($template, $to, $data) {
            echo "Email sent to $to with template $template\n";
        }
    }
}

namespace AperturePro\Storage {
    class StorageFactory {}
}

namespace AperturePro\Workflow {
    class Workflow {
        public static function onPaymentReceived($projectId) {
            echo "Workflow::onPaymentReceived called for project $projectId\n";
        }
    }
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
                $project->payment_status = 'pending';
                $project->title = 'Test Project';
                $project->package_price = 100.00;
                $project->payment_amount_received = 0.00;
                $project->payment_currency = 'USD';
                $project->payment_provider = null;
                $project->payment_intent_id = null;
                $project->payment_last_update = null;
                $project->booking_date = null;
                return $project;
            }
            return null;
        }
        public function get_var($query) { return 456; }
        public function update($table, $data, $where, $format=null, $where_format=null) {
            echo "WPDB Updated $table: " . json_encode($data) . "\n";
            return 1;
        }
        public function insert($table, $data, $format=null) {
            echo "WPDB Inserted into $table: " . json_encode($data) . "\n";
            return 1;
        }
        public function get_results($query) { return []; }
    }

    global $wpdb;
    $wpdb = new MockWPDB();

    function current_time($type) { return date('Y-m-d H:i:s'); }
    function sanitize_email($email) { return $email; }
    function set_transient($key, $val, $exp) { echo "Transient set: $key\n"; }
    function add_query_arg($key, $val, $url) { return $url . "?$key=$val"; }
    function home_url($path) { return "http://example.com" . $path; }
    function wp_json_encode($data) { return json_encode($data); }

    // Load the service
    require_once __DIR__ . '/../src/Services/PaymentService.php';

    use AperturePro\Services\PaymentService;

    echo "Testing handlePaymentSucceeded...\n";

    $event = [
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => 'pi_test_123',
                'amount_received' => 5000,
                'currency' => 'usd',
                'metadata' => ['project_id' => 123, 'client_email' => 'test@example.com']
            ]
        ]
    ];

    $result = PaymentService::processEvent($event);
    print_r($result);

    echo "\nTesting getPaymentSummary...\n";
    $summary = PaymentService::getPaymentSummary(123);
    print_r($summary);
}

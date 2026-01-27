<?php

/**
 * Benchmark: ClientProofController N+1 Logging
 *
 * GOAL:
 *  - Measure the number of database inserts (Logger::log calls) when proof generation fails for a batch of images.
 */

namespace {
    // 1. Mock WordPress Environment and Classes
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

    // Mock Global functions
    if (!function_exists('register_rest_route')) { function register_rest_route($ns, $route, $args) {} }
    if (!function_exists('current_time')) { function current_time($type, $gmt = 0) { return '2023-10-27 10:00:00'; } }
    if (!function_exists('get_transient')) { function get_transient($key) { return false; } }
    if (!function_exists('set_transient')) { function set_transient($key, $val, $exp) { return true; } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return $str; } }

    // Autoloader
    spl_autoload_register(function ($class) {
        // Map AperturePro namespace to src directory
        if (strpos($class, 'AperturePro\\') === 0) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });

    class MockWPDB {
        public $prefix = 'wp_';
        public $insert_count = 0;

        public function insert($table, $data, $format) {
            $this->insert_count++;
            return 1;
        }

        public function prepare($query, ...$args) { return $query; }
        public function get_var($query) { return null; }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Proof {
    class ProofService {
        public static function getProofUrls(array $images, $storage) {
            // Return empty array to simulate failure for all images
            return [];
        }
    }
}

namespace AperturePro\Storage {
    class StorageFactory {
        public static function create() {
            return new class {};
        }
    }
    class StorageInterface {}
}

namespace AperturePro\Repositories {
    class ProjectRepository {
        public function get_images_for_project($id) { return []; }
    }
}

namespace AperturePro\Auth {
    class CookieService {
        public static function getClientSession() { return ['project_id' => 1]; }
    }
}

namespace AperturePro\Config {
    class Config {
        public static function get($key, $default) { return $default; }
    }
}

namespace {
    use AperturePro\REST\ClientProofController;

    // Subclass controller to inject images
    class TestClientProofController extends ClientProofController {
        protected function get_project_images(int $project_id): array {
            $images = [];
            for ($i = 1; $i <= 500; $i++) {
                $images[] = [
                    'id' => $i,
                    'filename' => "image_{$i}.jpg",
                    'is_selected' => 0,
                    'comments' => []
                ];
            }
            return $images;
        }
    }

    // Run Benchmark
    echo "Starting Benchmark: N+1 Logger...\n";

    $controller = new TestClientProofController();
    $request = new \WP_REST_Request(['project_id' => 1]);

    // Reset counter
    global $wpdb;
    $wpdb->insert_count = 0;

    $start = microtime(true);
    // BaseController::respond_error uses Logger, but we are testing list_proofs which logs errors in the loop.
    // However, list_proofs calls get_project_images which we mocked.
    // It calls StorageFactory::create.
    // It calls ProofService::getProofUrls (mocked to return []).
    // Then it loops.
    $response = $controller->list_proofs($request);
    $end = microtime(true);

    $duration = $end - $start;
    $queries = $wpdb->insert_count;

    echo "Processed 500 images.\n";
    echo "Time: " . number_format($duration, 4) . "s\n";
    echo "DB Inserts (Logger calls): " . $queries . "\n";

    if ($queries >= 500) {
        echo "BASELINE CONFIRMED: N+1 issue present.\n";
    } else {
        echo "WARNING: N+1 issue NOT reproduced (Queries: $queries).\n";
    }
}

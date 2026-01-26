<?php
/**
 * Benchmark for PortalRenderer gallery fetching.
 * Usage: php tests/benchmark_portal_gallery.php
 */

namespace {
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
    if (!defined('APERTURE_PRO_FILE')) {
        define('APERTURE_PRO_FILE', __FILE__);
    }

    // Mock Functions
    function get_transient($key) { return false; }
    function set_transient($key, $value, $expiration) { return true; }
    function delete_transient($key) { return true; }
    function wp_cache_get($key, $group = '', $force = false, &$found = null) { $found = false; return false; }
    function wp_cache_set($key, $data, $group = '', $expire = 0) { return true; }
    function wp_cache_delete($key, $group = '') { return true; }
    function esc_html($s) { return $s; }
    function esc_attr($s) { return $s; }
    function esc_url($s) { return $s; }
    function plugin_dir_path($file) { return __DIR__ . '/../'; }
    function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
    function home_url($path = '') { return 'http://example.com' . $path; }
    function apply_filters($tag, $value) { return $value; }
    function wp_tempnam($prefix) { return sys_get_temp_dir() . '/' . uniqid($prefix); }
    function is_wp_error($thing) { return false; }
    function wp_remote_retrieve_response_code($response) { return 200; }
    function sanitize_text_field($str) { return trim($str); }

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        public $results_to_return = [];
        public $row_to_return = null;
        public $var_to_return = null;
        public $last_query = '';

        public function prepare($query, ...$args) {
            return vsprintf(str_replace(['%d', '%s'], ['%s', '%s'], $query), $args);
        }

        public function get_results($query, $output = OBJECT) {
            $this->last_query = $query;
            // If query is for images, return the large set
            if (strpos($query, 'ap_images') !== false && strpos($query, 'SELECT *') !== false) {
                $res = $this->results_to_return;
                // Simple parser for LIMIT/OFFSET
                if (preg_match('/LIMIT\s+(\d+)(\s+OFFSET\s+(\d+))?/i', $query, $matches)) {
                    $limit = (int)$matches[1];
                    $offset = isset($matches[3]) ? (int)$matches[3] : 0;
                    return array_slice($res, $offset, $limit);
                }
                return $res;
            }
            return [];
        }

        public function get_row($query) {
            $this->last_query = $query;
            return $this->row_to_return;
        }

        public function get_var($query) {
            $this->last_query = $query;
            // Check for COUNT(*)
            if (preg_match('/SELECT COUNT\(\*\)/i', $query)) {
                return count($this->results_to_return);
            }
            return $this->var_to_return;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

// Mock Classes
namespace AperturePro\Storage {
    interface StorageInterface {
        public function existsMany(array $paths): array;
        public function signMany(array $paths): array;
        public function getUrl(string $path, array $options = []): string;
        public function exists(string $path): bool;
        public function upload($file, $path, $options = []);
    }

    class StorageFactory {
        public static function make() {
            return new MockStorage();
        }
        public static function create() {
            return new MockStorage();
        }
    }

    class MockStorage implements StorageInterface {
        public function existsMany(array $paths): array {
            // Simulate 1ms per 100 items latency
            usleep(10 * count($paths));
            $res = [];
            foreach ($paths as $p) $res[$p] = true;
            return $res;
        }
        public function signMany(array $paths): array {
             // Simulate signing latency
            usleep(10 * count($paths));
            $res = [];
            foreach ($paths as $p) $res[$p] = 'http://s3.example.com/' . $p . '?signed=1';
            return $res;
        }
        public function getUrl(string $path, array $options = []): string {
            return 'http://s3.example.com/' . $path;
        }
        public function exists(string $path): bool {
            return true;
        }
        public function upload($file, $path, $options = []) {}
    }
}

namespace AperturePro\Auth {
    class CookieService {
        public static function getClientSession() { return null; }
    }
}

namespace AperturePro\Helpers {
    class Utils {}
    class Logger {
        public static function log($level, $type, $msg, $ctx = []) {}
    }
}

namespace AperturePro\Repositories {
    class ProjectRepository {
        public function find($id) {
            return (object)[
                'id' => $id,
                'client_id' => 1,
                'title' => 'Test Project',
                'status' => 'active',
                'session_date' => '2023-01-01',
                'created_at' => '2023-01-01',
                'updated_at' => '2023-01-01',
                'photographer_notes' => 'Notes',
                'payment_status' => 'paid'
            ];
        }
    }
}

namespace AperturePro\Proof {
    class ProofCache {
        public static function generateKey($type, $data) { return md5(serialize($data)); }
        public static function get($key) { return null; }
        public static function set($key, $val) {}
    }
    class ProofQueue {
        public static function markProofsAsExisting($ids) {}
        public static function addBatch($items) {}
        public static function enqueueBatch($items) {}
        public static function add($pid, $id) {}
        public static function enqueue($orig, $proof) {}
    }
}

namespace AperturePro\Config {
    class Config {
        public static function get($key, $default = null) { return $default; }
    }
}

// Global scope to run the test
namespace {
    // Load Source Files
    require_once __DIR__ . '/../src/Proof/ProofService.php';
    require_once __DIR__ . '/../src/ClientPortal/PortalRenderer.php';

    use AperturePro\ClientPortal\PortalRenderer;

    // Setup Data
    $galleryId = 10;
    $projectId = 123;
    $numImages = 2000;

    global $wpdb;
    $wpdb->row_to_return = (object)[
        'id' => $galleryId,
        'project_id' => $projectId,
        'type' => 'proof',
        'status' => 'active',
        'created_at' => '2023-01-01',
        'name' => 'Client Name',
        'email' => 'client@example.com',
        'phone' => '123'
    ];

    $images = [];
    for ($i = 0; $i < $numImages; $i++) {
        $images[] = (object)[
            'id' => 1000 + $i,
            'gallery_id' => $galleryId,
            'storage_key_original' => "projects/{$projectId}/img_{$i}.jpg",
            'has_proof' => 1,
            'client_comments' => '[]',
            'is_selected' => 0,
            'sort_order' => $i
        ];
    }
    // Limit results for the "query" to 50 if pagination is working
    // But our MockWPDB just returns $results_to_return.
    // In reality, the query would use LIMIT.
    // The Renderer will call get_results with LIMIT 50.
    // We need our MockWPDB to handle LIMIT or we just give it 50 items to simulate the DB response.

    // However, the Renderer calls get_var for COUNT(*) first.
    // We need to handle that in the MockWPDB.

    // Reset DB mock state
    $wpdb->results_to_return = $images;

    // Inject $_GET for pagination
    $_GET['page'] = 1;

    echo "Benchmarking PortalRenderer with {$numImages} total images (Pagination Enabled)...\n";

    // Instantiate Renderer
    $renderer = new PortalRenderer();
    // Use reflection to access protected gatherContext
    $reflection = new \ReflectionClass($renderer);
    $method = $reflection->getMethod('gatherContext');
    $method->setAccessible(true);

    // Run Benchmark
    $start = microtime(true);
    $startMem = memory_get_usage();

    // Run the method
    $context = $method->invoke($renderer, $projectId);

    $end = microtime(true);
    $endMem = memory_get_usage();

    echo "Time: " . number_format($end - $start, 4) . "s\n";
    echo "Memory: " . number_format(($endMem - $startMem) / 1024 / 1024, 2) . " MB\n";
    echo "Images in context: " . count($context['images']) . "\n";
}

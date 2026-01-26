<?php
namespace {
    // Define constants
    if (!defined('APERTURE_PRO_FILE')) define('APERTURE_PRO_FILE', __FILE__);

    // Mock Global Functions
    if (!function_exists('plugin_dir_path')) {
        function plugin_dir_path($file) {
            return __DIR__ . '/../';
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient($key) {
            return [];
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html($s) {
            return $s;
        }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        }
    }
    if (!function_exists('home_url')) {
        function home_url($path = '') {
            return 'http://example.com' . $path;
        }
    }
    if (!function_exists('esc_url')) {
        function esc_url($url) {
            return $url;
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($t) { return false; }
    }

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        public $last_query = '';

        public function prepare($query, ...$args) {
            return $query; // Simplified
        }

        public function get_row($query) {
            if (strpos($query, 'ap_clients') !== false) {
                return (object)[
                    'id' => 1, 'name' => 'Client Name', 'email' => 'c@example.com', 'phone' => '123'
                ];
            }
            if (strpos($query, 'ap_galleries') !== false) {
                return (object)[
                    'id' => 10, 'status' => 'published', 'created_at' => '2023-01-01'
                ];
            }
            if (strpos($query, 'ap_download_tokens') !== false) {
                return null;
            }
            return null;
        }

        public function get_results($query) {
            if (strpos($query, 'ap_images') !== false) {
                // Return dummy images with HIGH RES paths
                return [
                    (object)[
                        'id' => 101,
                        'gallery_id' => 10,
                        'storage_key_original' => 'projects/1/DSC_0001.jpg',
                        'client_comments' => '[]',
                        'is_selected' => 0,
                        'sort_order' => 1
                    ],
                    (object)[
                        'id' => 102,
                        'gallery_id' => 10,
                        'storage_key_original' => 'projects/1/DSC_0002.jpg',
                        'client_comments' => '[]',
                        'is_selected' => 0,
                        'sort_order' => 2
                    ]
                ];
            }
            return [];
        }

        public function get_var($query) {
            return null;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $ctx, $msg, $meta = []) {
            echo "[LOG] $msg\n";
        }
    }
    class Utils {}
}

namespace AperturePro\Auth {
    class CookieService {
        public static function getClientSession() {
            return [];
        }
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
                'photographer_notes' => '',
                'payment_status' => 'unpaid'
            ];
        }
    }
}

namespace AperturePro\Storage {
    interface StorageInterface {
        public function signMany(array $paths);
        // Add other methods if needed
    }

    class MockStorage implements StorageInterface {
        public $signedPaths = [];

        public function signMany(array $paths) {
            echo "Storage::signMany called with " . count($paths) . " paths.\n";
            foreach ($paths as $p) {
                echo " - Signing Path: $p\n";
                $this->signedPaths[] = $p;
            }
            $results = [];
            foreach ($paths as $p) {
                $results[$p] = 'http://cdn/signed/' . $p;
            }
            return $results;
        }

        public static function create() { return new self(); }
    }

    class StorageFactory {
        public static function make() {
            return new MockStorage();
        }
        public static function create() {
            return new MockStorage();
        }
    }
}

namespace AperturePro\Proof {
    class ProofService {
        public static function getProofUrls(array $images, $storage = null) {
            echo "ProofService::getProofUrls called with " . count($images) . " images.\n";
            $urls = [];
            foreach ($images as $key => $img) {
                // Mock logic: return proof url
                $original = $img['path'] ?? $img['filename'] ?? 'unknown';
                echo " - Processing Proof for Original: $original\n";
                $urls[$key] = 'http://cdn/proofs/derived_from_' . basename($original);
            }
            return $urls;
        }
    }
}

namespace {
    // Include the class under test
    require_once __DIR__ . '/../src/ClientPortal/PortalRenderer.php';
    use AperturePro\ClientPortal\PortalRenderer;

    echo "--- STARTING TEST ---\n";
    $renderer = new PortalRenderer();

    // We access the protected method via reflection or just run renderPortal and inspect output/logs
    // But renderPortal captures output.
    // The logs ("Signing Path: ...") will print to stdout because we echo in the mocks.

    $renderer->renderPortal(123);
    echo "--- END TEST ---\n";
}

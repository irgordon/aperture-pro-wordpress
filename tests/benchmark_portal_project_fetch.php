<?php
// tests/benchmark_portal_project_fetch.php

namespace {
    // 1. Mock Global WP Functions and Constants
    if (!defined('OBJECT')) define('OBJECT', 'OBJECT');

    // Global mocks
    global $wpdb;
    global $wp_object_cache;
    $wp_object_cache = [];

    // Mock wp_cache_* functions
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get($key, $group = '', $force = false, &$found = null) {
            global $wp_object_cache;
            $cache_key = $group . ':' . $key;
            if (isset($wp_object_cache[$cache_key])) {
                $found = true;
                return $wp_object_cache[$cache_key];
            }
            $found = false;
            return false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set($key, $data, $group = '', $expire = 0) {
            global $wp_object_cache;
            $cache_key = $group . ':' . $key;
            $wp_object_cache[$cache_key] = $data;
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete($key, $group = '') {
            global $wp_object_cache;
            $cache_key = $group . ':' . $key;
            unset($wp_object_cache[$cache_key]);
            return true;
        }
    }

    // Mock other WP functions used in PortalRenderer
    if (!function_exists('plugin_dir_path')) {
        function plugin_dir_path($file) {
            return __DIR__ . '/../';
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient($transient) {
            return false;
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html($text) {
            return $text;
        }
    }

    if (!function_exists('home_url')) {
        function home_url($path = '') {
            return 'http://example.com' . $path;
        }
    }

    if (!function_exists('add_query_arg')) {
        function add_query_arg($key, $value, $url) {
            return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value;
        }
    }

    if (!function_exists('is_ssl')) {
        function is_ssl() {
            return true;
        }
    }

    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            return ['basedir' => '/tmp', 'baseurl' => 'http://example.com/uploads'];
        }
    }

    // Mock DB
    class MockWPDB {
        public $prefix = 'wp_';
        public $query_count = 0;
        public $last_query;

        public function get_row($query, $output = OBJECT, $y = 0) {
            $this->query_count++;
            $this->last_query = $query;

            // Simulate DB latency
            usleep(1000);

            if (strpos($query, 'ap_projects') !== false) {
                 return (object) [
                    'id' => 1,
                    'title' => 'Test Project',
                    'client_id' => 1,
                    'status' => 'active',
                    'session_date' => '2023-01-01',
                    'created_at' => '2023-01-01',
                    'updated_at' => '2023-01-01',
                    'photographer_notes' => 'Notes',
                    'payment_status' => 'paid'
                ];
            }

            return null; // Return null for other queries to keep it simple
        }

        // Mock get_results for galleries/images if needed, but we focus on project fetch
        public function get_results($query) {
             $this->query_count++;
             return [];
        }

        public function get_var($query) {
            $this->query_count++;
            return null;
        }

        public function prepare($query, ...$args) {
            // Check if args is array or individual args
            if (isset($args[0]) && is_array($args[0])) {
                $args = $args[0];
            }
            return vsprintf(str_replace(['%d', '%s'], ['%d', '%s'], $query), $args);
        }
    }

    $wpdb = new MockWPDB();
}

namespace AperturePro\Auth {
    // Mock CookieService
    class CookieService {
        public static function getClientSession(): ?array {
            return ['client_id' => 1, 'project_id' => 1];
        }
    }
}

namespace AperturePro\Helpers {
    // Mock Logger
    class Logger {
        public static function log($level, $type, $message, $context = []) {}
    }

    // Mock Utils
    class Utils {}
}

namespace AperturePro\Storage {
    // Mock StorageFactory
    class StorageFactory {
        public static function make() {
            return new class {
                public function signMany($paths) { return []; }
            };
        }
    }
}

namespace {
    // 2. Load classes
    require_once __DIR__ . '/../src/Repositories/ProjectRepository.php';
    require_once __DIR__ . '/../src/ClientPortal/PortalRenderer.php';

    use AperturePro\ClientPortal\PortalRenderer;

    // Expose protected method
    class TestPortalRenderer extends PortalRenderer {
        public function publicGatherContext(int $projectId): array {
            return $this->gatherContext($projectId);
        }
    }

    // 3. Benchmark
    echo "Benchmarking PortalRenderer project fetch...\n";

    $renderer = new TestPortalRenderer();
    $iterations = 50;
    $projectId = 1;

    // Baseline run
    $wpdb->query_count = 0;
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $renderer->publicGatherContext($projectId);
    }

    $end = microtime(true);
    $duration = $end - $start;

    echo "Iterations: $iterations\n";
    echo "Total Time: " . number_format($duration, 4) . " s\n";
    echo "Avg Time: " . number_format(($duration / $iterations) * 1000, 4) . " ms\n";
    echo "DB Queries: " . $wpdb->query_count . "\n";
    echo "DB Queries per iteration: " . ($wpdb->query_count / $iterations) . "\n";
}

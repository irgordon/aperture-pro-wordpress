<?php
// tests/benchmark_project_repository.php

namespace {
    // 1. Mock Global WP Functions and Classes
    if (!defined('OBJECT')) define('OBJECT', 'OBJECT');

    // Simple in-memory cache mock
    global $wp_object_cache;
    $wp_object_cache = [];

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

    // 2. Mock DB
    class MockWPDB {
        public $prefix = 'wp_';
        public $query_count = 0;

        public function get_row($query, $output = OBJECT, $y = 0) {
            $this->query_count++;
            // Simulate DB latency (1ms)
            usleep(1000);
            return (object) ['id' => 1, 'name' => 'Test Project'];
        }

        public function prepare($query, ...$args) {
            return $query;
        }

        public function update($table, $data, $where) {
             // Simulate DB latency
             usleep(1000);
             return true;
        }
    }

    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Repositories {
    // Mock class for testing if not loaded
}

namespace {
    // 3. Load the Class
    require_once __DIR__ . '/../src/Repositories/ProjectRepository.php';

    use AperturePro\Repositories\ProjectRepository;

    // 4. Run Benchmark
    echo "Benchmarking ProjectRepository::find...\n";

    $repo = new ProjectRepository();
    $iterations = 1000;

    // Warm up/ensure clean state
    global $wp_object_cache;
    $wp_object_cache = [];
    $wpdb->query_count = 0;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        // We look for ID 1 repeatedly
        $repo->find(1);
    }

    $end = microtime(true);
    $duration = $end - $start;

    echo "Iterations: $iterations\n";
    echo "Time taken: " . number_format($duration, 4) . " seconds\n";
    echo "Avg time per call: " . number_format(($duration / $iterations) * 1000, 4) . " ms\n";
    echo "DB Query Calls: " . $wpdb->query_count . "\n";
}

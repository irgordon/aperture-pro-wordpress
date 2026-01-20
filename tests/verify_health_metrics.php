<?php

namespace AperturePro\Config {
    class Config {
        public static function all() { return []; }
        public static function get($key) { return null; }
    }
}

namespace AperturePro\Storage {
    class StorageFactory {
        public static function make() {
            return new class {
                public function getStats() {
                    return [
                        'healthy' => true,
                        'used_human' => '1GB',
                        'available_human' => '9GB'
                    ];
                }
                public function getName() { return 'MockStorage'; }
            };
        }
    }
}

namespace AperturePro\Helpers {
    class Logger {
        public static function log($level, $context, $message, $meta = []) {
            // echo "LOG: [$level] $message\n";
        }
    }
}

namespace {
    // Mock WP functions
    function get_option($key, $default = false) { return $default; }
    function get_transient($key) { return false; }
    function current_time($type) { return date('Y-m-d H:i:s'); }
    function wp_json_encode($data) { return json_encode($data); }

    // Mock WPDB
    class MockWPDB {
        public $prefix = 'wp_';
        private $mocked_counts = [];
        private $current_mock_index = 0;
        private $mock_tables = [];

        public function set_mock_count($count) {
            $this->mocked_counts[] = $count;
        }

        public function set_table_exists($table, $exists) {
            $this->mock_tables[$table] = $exists;
        }

        public function prepare($query, ...$args) {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_col($query) { return []; }

        public function get_var($query) {
            if (stripos($query, 'SHOW TABLES') !== false) {
                // simple parsing to find table name in 'SHOW TABLES LIKE '...'
                if (preg_match("/LIKE '(.+)'/", $query, $matches)) {
                    $table = $matches[1];
                    if (isset($this->mock_tables[$table]) && $this->mock_tables[$table]) {
                        return $table;
                    }
                }
                return null;
            }
            if (stripos($query, 'SELECT COUNT') !== false) {
                // Return next mocked count or 0
                $count = $this->mocked_counts[$this->current_mock_index] ?? 0;
                $this->current_mock_index++;
                return $count;
            }
            return 0;
        }

        public function reset() {
            $this->mocked_counts = [];
            $this->current_mock_index = 0;
            $this->mock_tables = [];
        }
    }

    // Initialize mock DB
    global $wpdb;
    $wpdb = new MockWPDB();

    // Include the class under test
    require_once __DIR__ . '/../src/Health/HealthService.php';

    use AperturePro\Health\HealthService;

    // TEST CASE 1: 50 Images (Matches legacy hardcoded values)
    echo "TEST 1: 50 Images\n";
    $wpdb->reset();
    $wpdb->set_table_exists('wp_ap_images', true);
    $wpdb->set_mock_count(50);

    $metrics = HealthService::getMetrics();
    print_r($metrics['performance']);

    $p = $metrics['performance'];
    if ($p['requestCountBefore'] === 500 && $p['requestCountAfter'] === 50 && $p['requestReduction'] === '−90%' && $p['latencySaved'] === '−22.5s') {
        echo "PASS: 50 images match legacy baseline.\n\n";
    } else {
        echo "FAIL: 50 images did not match expected values.\n\n";
    }

    // TEST CASE 2: 0 Images
    echo "TEST 2: 0 Images\n";
    $wpdb->reset();
    $wpdb->set_table_exists('wp_ap_images', true);
    $wpdb->set_mock_count(0);

    $metrics = HealthService::getMetrics();
    print_r($metrics['performance']);

    $p = $metrics['performance'];
    if ($p['requestCountBefore'] === 0 && $p['requestCountAfter'] === 0 && $p['requestReduction'] === '—' && $p['latencySaved'] === '—') {
        echo "PASS: 0 images handled correctly.\n\n";
    } else {
        echo "FAIL: 0 images did not match expected values.\n\n";
    }

    // TEST CASE 3: 100 Images
    echo "TEST 3: 100 Images\n";
    $wpdb->reset();
    $wpdb->set_table_exists('wp_ap_images', true);
    $wpdb->set_mock_count(100);

    $metrics = HealthService::getMetrics();
    print_r($metrics['performance']);

    $p = $metrics['performance'];
    // 100 images -> 1000 legacy reqs -> 100 modern reqs. 900 saved. 900 * 0.05 = 45s.
    if ($p['requestCountBefore'] === 1000 && $p['requestCountAfter'] === 100 && $p['requestReduction'] === '−90%' && $p['latencySaved'] === '−45s') {
        echo "PASS: 100 images calculation correct.\n\n";
    } else {
        echo "FAIL: 100 images did not match expected values.\n\n";
    }
}

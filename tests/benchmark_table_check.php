<?php

class MockWPDB {
    public $prefix = 'wp_';
    // Simulating existing tables. 'ap_images' is intentionally missing to test that scenario.
    public $tables = [
        'wp_ap_projects',
        'wp_ap_clients',
        'wp_ap_galleries',
        // 'wp_ap_images',
        'wp_ap_magic_links',
        'wp_ap_download_tokens',
        'wp_ap_logs',
    ];

    public function get_var($query) {
        usleep(500); // 0.5ms latency per query simulation
        if (preg_match("/SHOW TABLES LIKE '(.*)'/", $query, $matches)) {
            $tableName = $matches[1];
            if (in_array($tableName, $this->tables)) {
                return $tableName;
            }
        }
        return null;
    }

    public function get_col($query) {
        usleep(500); // 0.5ms latency per query simulation
        // Return all tables matching the LIKE pattern
        // Simplified mock implementation: if it asks for ap_% return all ap_ tables
        if (strpos($query, "SHOW TABLES LIKE") !== false) {
             return $this->tables;
        }
        return [];
    }
}

$wpdb = new MockWPDB();

$requiredTables = [
    'ap_projects',
    'ap_clients',
    'ap_galleries',
    'ap_images',
    'ap_magic_links',
    'ap_download_tokens',
    'ap_logs',
];

function check_unoptimized($requiredTables) {
    global $wpdb;
    $results = [];
    foreach ($requiredTables as $table) {
        $full = $wpdb->prefix . $table;
        $exists = ($wpdb->get_var("SHOW TABLES LIKE '{$full}'") === $full);
        $results[$table] = $exists;
    }
    return $results;
}

function check_optimized($requiredTables) {
    global $wpdb;
    $results = [];

    // Fetch all tables matching the prefix in one query
    $like = $wpdb->prefix . '%';
    $foundTables = $wpdb->get_col("SHOW TABLES LIKE '{$like}'");

    // Flip for fast lookup (O(1))
    $foundMap = array_flip($foundTables);

    foreach ($requiredTables as $table) {
        $full = $wpdb->prefix . $table;
        $results[$table] = isset($foundMap[$full]);
    }
    return $results;
}

// Benchmark
$iterations = 100;

echo "Benchmarking Table Check ($iterations iterations)...\n";

// Run unoptimized
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    check_unoptimized($requiredTables);
}
$timeUnoptimized = microtime(true) - $start;
echo "Unoptimized: " . number_format($timeUnoptimized, 4) . " sec\n";

// Run optimized
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    check_optimized($requiredTables);
}
$timeOptimized = microtime(true) - $start;
echo "Optimized: " . number_format($timeOptimized, 4) . " sec\n";

// Validate Correctness
$resUnopt = check_unoptimized($requiredTables);
$resOpt = check_optimized($requiredTables);

if ($resUnopt !== $resOpt) {
    echo "ERROR: Results do not match!\n";
    print_r(['unopt' => $resUnopt, 'opt' => $resOpt]);
    exit(1);
} else {
    echo "Verification: Results match.\n";
}

if ($timeOptimized > 0) {
    echo "Speedup: " . number_format($timeUnoptimized / $timeOptimized, 1) . "x\n";
}

<?php

// Mock WPDB
class MockWPDB {
    public $prefix = 'wp_';

    public function get_var($query) {
        // Simulate DB query latency (e.g., 0.5ms)
        usleep(500);
        return 'proof_key'; // Pretend column exists
    }
}

$wpdb = new MockWPDB();

// Mock Transients
$transients = [];

function get_transient($key) {
    global $transients;
    // Simulate cache access latency (very fast)
    // usleep(10);
    return isset($transients[$key]) ? $transients[$key] : false;
}

function set_transient($key, $value, $expiration) {
    global $transients;
    $transients[$key] = $value;
    return true;
}

// Unoptimized Implementation
function check_schema_unoptimized() {
    global $wpdb;
    $imagesTable = $wpdb->prefix . 'ap_images';
    $hasColumn = $wpdb->get_var("SHOW COLUMNS FROM {$imagesTable} LIKE 'proof_key'");
    return !empty($hasColumn);
}

// Optimized Implementation (Class-based to match actual code)
class ProofService {
    private static $_hasProofKeyColumn = null;

    public static function reset() {
        self::$_hasProofKeyColumn = null;
    }

    public static function hasProofKeyColumn() {
        if (self::$_hasProofKeyColumn !== null) {
            return self::$_hasProofKeyColumn;
        }

        $cached = get_transient('ap_has_proof_key_column');
        if ($cached !== false) {
            return self::$_hasProofKeyColumn = (bool) $cached;
        }

        global $wpdb;
        $imagesTable = $wpdb->prefix . 'ap_images';

        $hasColumn = $wpdb->get_var("SHOW COLUMNS FROM `{$imagesTable}` LIKE 'proof_key'");
        $exists = !empty($hasColumn);

        set_transient('ap_has_proof_key_column', $exists ? 1 : 0, 86400);

        return self::$_hasProofKeyColumn = $exists;
    }
}

// Benchmark
$iterations = 1000;

echo "Benchmarking Schema Check ($iterations iterations)...\n";

// 1. Unoptimized
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    check_schema_unoptimized();
}
$timeUnoptimized = microtime(true) - $start;
echo "Unoptimized: " . number_format($timeUnoptimized, 4) . " sec\n";

// 2. Optimized (Simulating multiple requests in a single script run is hard,
// so we'll simulate the "steady state" where transient is populated,
// and static cache is populated per request)

// Scenario A: Static cache hit (same request)
// We call it repeatedly. This simulates many calls in ONE request.
ProofService::reset();
// First call populates static
ProofService::hasProofKeyColumn();

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    ProofService::hasProofKeyColumn();
}
$timeOptimizedStatic = microtime(true) - $start;
echo "Optimized (Static Hit): " . number_format($timeOptimizedStatic, 4) . " sec\n";

// Scenario B: Transient cache hit (new request)
// We reset static variable every time, but keep transient.
// This simulates $iterations REQUESTS where transient is already warm.

// Ensure transient is set
ProofService::reset();
ProofService::hasProofKeyColumn();

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    ProofService::reset(); // New request simulation
    ProofService::hasProofKeyColumn();
}
$timeOptimizedTransient = microtime(true) - $start;
echo "Optimized (Transient Hit): " . number_format($timeOptimizedTransient, 4) . " sec\n";

// Calculate Improvement (Transient Hit vs Unoptimized)
// This is the most fair comparison for "per request" overhead reduction.
if ($timeOptimizedTransient > 0) {
    $improvement = $timeUnoptimized / $timeOptimizedTransient;
    echo "Speedup (Transient vs Unoptimized): " . number_format($improvement, 1) . "x\n";
}

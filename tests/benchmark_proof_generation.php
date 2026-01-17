<?php
/**
 * Benchmark for Proof Generation (N+1 Storage Check + Cache)
 * Usage: php tests/benchmark_proof_generation.php
 */

require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Proof/ProofCache.php';
require_once __DIR__ . '/../src/Proof/ProofService.php';

// Mock Dependencies
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
}

// Mock Transients for ProofCache
$mock_transients = [];
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        return $mock_transients[$transient] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $mock_transients;
        $mock_transients[$transient] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }
}

class MockLogger {
    public static function log($level, $context, $message, $data = []) {}
}
if (!class_exists('AperturePro\Helpers\Logger')) {
    class_alias('MockLogger', 'AperturePro\Helpers\Logger');
}

use AperturePro\Storage\StorageInterface;
use AperturePro\Proof\ProofService;
use AperturePro\Proof\ProofCache;

// Mock Storage with Latency
class MockSlowStorage implements StorageInterface
{
    private $latencyMs;

    public function __construct(int $latencyMs = 10)
    {
        $this->latencyMs = $latencyMs;
    }

    public function getName(): string { return 'MockSlow'; }

    public function upload(string $source, string $target, array $options = []): string { return 'url'; }

    public function delete(string $target): void {}

    public function getUrl(string $target, array $options = []): string { return "http://cdn.example.com/$target"; }

    public function exists(string $target): bool
    {
        // Simulate network latency
        usleep($this->latencyMs * 1000);
        return true;
    }

    public function getStats(): array { return []; }

    public function existsMany(array $targets): array
    {
        // Simulate concurrent check (only 1x latency for the whole batch)
        usleep($this->latencyMs * 1000);
        $results = [];
        foreach ($targets as $target) {
            $results[$target] = true;
        }
        return $results;
    }
}

// Setup Data
$images = [];
for ($i = 0; $i < 50; $i++) {
    $images[] = [
        'id' => $i,
        'filename' => "image-$i.jpg",
        'path' => "projects/1/image-$i.jpg"
    ];
}

$storage = new MockSlowStorage(10); // 10ms latency per call

echo "Starting Benchmark (50 images, 10ms latency per exists check)...\n";

// Measure Baseline (Current Loop)
$start = microtime(true);

$proofs = [];
foreach ($images as $image) {
    try {
        // This calls ProofService::getProofUrlForImage which calls $storage->exists()
        $proofUrl = ProofService::getProofUrlForImage($image, $storage);
        $proofs[] = $proofUrl;
    } catch (\Throwable $e) {
        // ignore
    }
}

$end = microtime(true);
$duration = $end - $start;

echo sprintf("Baseline Duration (N+1): %.4f seconds\n", $duration);
echo sprintf("Average per image: %.4f seconds\n", $duration / 50);

// Simple validation
if (count($proofs) !== 50) {
    echo "ERROR: Expected 50 proofs, got " . count($proofs) . "\n";
    exit(1);
}

// Test Optimized + Cached Version
if (method_exists(ProofService::class, 'getProofUrls')) {
    echo "\nTesting Optimized Version (Cold Cache)...\n";
    // Ensure cache is empty
    global $mock_transients;
    $mock_transients = [];

    $startOpt = microtime(true);
    $proofUrlsMap = ProofService::getProofUrls($images, $storage);
    $endOpt = microtime(true);
    $durationOpt = $endOpt - $startOpt;

    echo sprintf("Optimized Duration (Cold): %.4f seconds\n", $durationOpt);
    echo sprintf("Improvement vs Baseline: %.2fx faster\n", $duration / $durationOpt);

    // Test Cache Hit
    echo "\nTesting Optimized Version (Warm Cache)...\n";
    $startCache = microtime(true);
    $proofUrlsMapCached = ProofService::getProofUrls($images, $storage);
    $endCache = microtime(true);
    $durationCache = $endCache - $startCache;

    echo sprintf("Optimized Duration (Warm): %.6f seconds\n", $durationCache);
    if ($durationOpt > 0) {
        echo sprintf("Improvement vs Cold: %.2fx faster\n", $durationOpt / $durationCache);
    }

    // Validate result count
    if (count($proofUrlsMapCached) !== 50) {
        echo "ERROR: Warm cache returned " . count($proofUrlsMapCached) . " items\n";
    }

} else {
    echo "\nOptimized ProofService::getProofUrls not yet implemented.\n";
}

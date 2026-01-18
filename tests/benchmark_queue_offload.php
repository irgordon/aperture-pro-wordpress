<?php
/**
 * Benchmark for Proof Generation Offloading
 * Usage: php tests/benchmark_queue_offload.php
 */

require_once __DIR__ . '/../src/Storage/StorageInterface.php';
require_once __DIR__ . '/../src/Storage/StorageFactory.php'; // Needed if ProofQueue calls it
require_once __DIR__ . '/../src/Proof/ProofCache.php';
require_once __DIR__ . '/../src/Proof/ProofQueue.php'; // ADDED
require_once __DIR__ . '/../src/Proof/ProofService.php';

// Mock Dependencies
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') { return tempnam(sys_get_temp_dir(), $prefix); }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) { return true; }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; }
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
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}
function current_time($type) { return date('Y-m-d H:i:s'); }

class MockLogger {
    public static function log($level, $context, $message, $data = []) {}
}
if (!class_exists('AperturePro\Helpers\Logger')) {
    class_alias('MockLogger', 'AperturePro\Helpers\Logger');
}

use AperturePro\Storage\StorageInterface;
use AperturePro\Proof\ProofService;
use AperturePro\Proof\ProofCache;

// Mock Storage that simulates SLOW upload (generation) and MISSING proofs
class MockMissingStorage implements StorageInterface
{
    private $uploadLatencyMs;

    public function __construct(int $uploadLatencyMs = 100)
    {
        $this->uploadLatencyMs = $uploadLatencyMs;
    }

    public function getName(): string { return 'MockMissing'; }

    public function upload(string $source, string $target, array $options = []): string {
        // Simulate slow upload/generation
        usleep($this->uploadLatencyMs * 1000);
        return 'url';
    }

    public function delete(string $target): void {}

    public function getUrl(string $target, array $options = []): string { return "http://cdn.example.com/$target"; }

    public function exists(string $target): bool
    {
        return false; // Always missing
    }

    public function getStats(): array { return []; }

    public function existsMany(array $targets): array
    {
        // All missing
        $results = [];
        foreach ($targets as $target) {
            $results[$target] = false;
        }
        return $results;
    }
}

// Setup Data
$images = [];
for ($i = 0; $i < 10; $i++) {
    $images[] = [
        'id' => $i,
        'filename' => "image-$i.jpg",
        'path' => "projects/1/image-$i.jpg"
    ];
}

// Create a dummy file to act as "original" so generateProofVariant doesn't fail on download
$dummyOriginal = tempnam(sys_get_temp_dir(), 'orig');
file_put_contents($dummyOriginal, 'dummy image data');

class MockLocalMissingStorage extends MockMissingStorage {
    private $dummyFile;
    public function __construct($dummyFile, $latency) {
        parent::__construct($latency);
        $this->dummyFile = $dummyFile;
    }
    public function getUrl(string $target, array $options = []): string {
        return $this->dummyFile; // return local path so file_get_contents works
    }
    public function getLocalPath(string $path): ?string {
        return $this->dummyFile;
    }
}

$storage = new MockLocalMissingStorage($dummyOriginal, 200); // 200ms latency per upload

echo "Starting Benchmark (10 images, all missing, 200ms generation latency)...\n";
echo "Expectation: Should be nearly instant because generation is queued.\n";

$start = microtime(true);

// This will trigger generation for all 10 images
$proofUrlsMap = ProofService::getProofUrls($images, $storage);

$end = microtime(true);
$duration = $end - $start;

echo sprintf("Duration: %.4f seconds\n", $duration);
echo sprintf("Average per image: %.4f seconds\n", $duration / 10);

if ($duration < 0.1) {
    echo "SUCCESS: It was very fast! Offloading works.\n";
} else {
    echo "WARNING: It was slower than expected (>0.1s).\n";
}

// Cleanup
@unlink($dummyOriginal);

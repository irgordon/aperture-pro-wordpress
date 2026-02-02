<?php
/**
 * Benchmark: ProofService Loop vs Batch
 *
 * Demonstrates the N+1 performance issue in `getProofUrlForImage` loops
 * versus the optimized batch `getProofUrls`.
 *
 * Usage: php tests/benchmark_proof_service_get_proof_urls.php
 */

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);

    // --- Mocks for WP Functions ---
    if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
    if (!function_exists('set_transient')) { function set_transient($k, $v, $e) { return true; } }
    if (!function_exists('esc_url')) { function esc_url($u) { return $u; } }
    if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
    if (!function_exists('plugins_url')) { function plugins_url($p) { return 'http://test/'.$p; } }

    // --- Load Real Interfaces ---
    require_once __DIR__ . '/../src/Storage/StorageInterface.php';

    // --- Mock Storage Implementation with Latency ---
    class BenchmarkStorage implements \AperturePro\Storage\StorageInterface {
        private $latency;
        public function __construct($latency = 10000) { $this->latency = $latency; }

        public function getName(): string { return 'Benchmark'; }
        public function upload(string $s, string $t, array $o = []): string { return ''; }
        public function uploadMany(array $f): array { return []; }
        public function delete(string $t): void {}

        public function exists(string $target): bool {
            usleep($this->latency);
            return true;
        }
        public function existsMany(array $targets): array {
            usleep($this->latency); // Optimized: 1 call for N items
            return array_fill_keys($targets, true);
        }
        public function getUrl(string $target, array $options = []): string {
            if (!empty($options['signed'])) usleep($this->latency);
            return 'http://cdn/'.$target;
        }
        public function sign(string $p): ?string { return null; }
        public function signMany(array $paths): array {
            usleep($this->latency); // Optimized: 1 call for N items
            $res = [];
            foreach ($paths as $p) $res[$p] = 'http://cdn/'.$p;
            return $res;
        }
        public function getStats(): array { return []; }
    }
}

// --- Mock Classes in Namespaces ---

namespace AperturePro\Proof {
    class ProofQueue {
        public static function enqueue($o, $p, $pid, $iid) {}
        public static function markProofsAsExisting($ids) {}
        public static function enqueueBatch($items) {}
    }
}

namespace AperturePro\Config {
    class Config {
        public static function get($k, $d=null) { return $d; }
    }
}

namespace AperturePro\Storage {
    class StorageFactory {
        public static function create() { return new \BenchmarkStorage(); }
    }
}

// --- Run Benchmark ---
namespace {
    // Load System Under Test
    require_once __DIR__ . '/../src/Proof/ProofCache.php';
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Proof\ProofService;
    use BenchmarkStorage;

    $count = 50;
    $latencyMs = 10; // 10ms network latency
    $latencyUs = $latencyMs * 1000;

    echo "--------------------------------------------------\n";
    echo " BENCHMARK: Proof Generation (N=$count, Latency={$latencyMs}ms)\n";
    echo "--------------------------------------------------\n";

    $storage = new BenchmarkStorage($latencyUs);
    $images = [];
    for ($i=0; $i<$count; $i++) {
        $images[] = [
            'id' => $i,
            'filename' => "img$i.jpg",
            'path' => "projects/1/img$i.jpg",
            'project_id' => 1,
            'has_proof' => 0
        ];
    }

    // 1. Loop (N+1)
    // Each item: exists() + getUrl(signed) = 2 calls
    // Total: N * 2 calls
    $start = microtime(true);
    foreach ($images as $img) {
        ProofService::getProofUrlForImage($img, $storage);
    }
    $loopTime = microtime(true) - $start;
    echo "Sequential Loop: " . number_format($loopTime, 4) . "s\n";

    // 2. Batch
    // Total: existsMany() + signMany() = 2 calls
    $start = microtime(true);
    ProofService::getProofUrls($images, $storage);
    $batchTime = microtime(true) - $start;
    echo "Optimized Batch: " . number_format($batchTime, 4) . "s\n";

    if ($batchTime > 0) {
        $speedup = $loopTime / $batchTime;
        echo "Speedup Factor:  " . number_format($speedup, 1) . "x\n";
    }
    echo "--------------------------------------------------\n";
}

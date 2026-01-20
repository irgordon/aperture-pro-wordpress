<?php

// Mock WordPress functions
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($prefix = '') {
        return tempnam(sys_get_temp_dir(), $prefix);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return 200;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

// Mock Logger
namespace AperturePro\Helpers;
class Logger {
    public static function log($level, $context, $message, $data = []) {
        // echo "[$level] $message\n";
    }
}

namespace AperturePro\Storage;
interface StorageInterface {
    public function getUrl(string $path, array $options = []): string;
    public function upload(string $source, string $destination, array $options = []): array;
    public function exists(string $path): bool;
    public function existsMany(array $paths): array;
    public function getLocalPath(string $path): ?string;
}

class MockStorage implements StorageInterface {
    public function getUrl(string $path, array $options = []): string {
        return "http://example.com/$path";
    }
    public function upload(string $source, string $destination, array $options = []): array {
        return ['url' => "http://example.com/$destination"];
    }
    public function exists(string $path): bool { return false; }
    public function existsMany(array $paths): array { return []; }
    public function getLocalPath(string $path): ?string { return null; }
}

// Mock wp_remote_get for the baseline
// We simulate network latency
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        // Simulate 0.5s latency per request
        usleep(500000);

        // Write some dummy data to the file if stream is set
        if (!empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents($args['filename'], 'fake image data');
        }

        return ['response' => ['code' => 200]];
    }
}

// Load the class under test
require_once __DIR__ . '/../src/Proof/ProofService.php';

use AperturePro\Proof\ProofService;

// Benchmark
$start = microtime(true);

$storage = new MockStorage();
$images = [
    'img1.jpg',
    'img2.jpg',
    'img3.jpg',
    'img4.jpg',
    'img5.jpg'
];

echo "Starting sequential processing of " . count($images) . " images...\n";

foreach ($images as $img) {
    $proofPath = "proofs/$img";
    // We mock createWatermarkedLowRes to avoid needing GD/Imagick for the benchmark
    // But since it's protected, we can't easily mock it without extending the class or reflection?
    // Actually, createWatermarkedLowRes checks extensions. If none, it just copies.
    // In this environment, maybe we don't have GD/Imagick?
    // Let's assume the latency is dominated by download.

    ProofService::generateProofVariant($img, $proofPath, $storage);
}

$end = microtime(true);
$duration = $end - $start;

echo "Total duration: " . number_format($duration, 4) . " seconds\n";

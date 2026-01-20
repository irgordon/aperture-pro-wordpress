<?php

require_once __DIR__ . '/../src/Storage/AbstractStorage.php';
require_once __DIR__ . '/../src/Storage/Traits/Retryable.php';
require_once __DIR__ . '/../src/Storage/ImageKit/Capabilities.php';
require_once __DIR__ . '/../src/Storage/ImageKit/ImageKitUploader.php';
require_once __DIR__ . '/../src/Storage/Retry/RetryExecutor.php';
require_once __DIR__ . '/../src/Storage/Chunking/ChunkedUploader.php';
require_once __DIR__ . '/../src/Storage/Upload/UploadRequest.php';
require_once __DIR__ . '/../src/Storage/ImageKitStorage.php';

// Mock Logger
class MockLogger {
    public static function log($level, $type, $message, $context = []) {
        // Silent
    }
}
class_alias('MockLogger', 'AperturePro\Helpers\Logger');

// Mock ImageKit SDK
namespace ImageKit {
    class ImageKit {
        public function __construct($p, $pr, $u) {}

        public function getFileDetails($fileId) {
            // Simulate 50ms network latency
            usleep(50000);
            return (object)['fileId' => $fileId, 'name' => basename($fileId)];
        }

        public function listFiles($params) {
            // Simulate 50ms network latency (same as single call, but returns many)
            usleep(50000);

            $path = $params['path'] ?? '/';
            // Simulate finding requested files
            // In a real mock, we would parse searchQuery, but here we just return a hit for the purpose of the benchmark structure
            return (object)[
                'success' => true,
                // Return a list of fake file objects
                'result' => array_map(function($i) {
                    return (object)['name' => "file_$i.jpg", 'filePath' => "/path/to/file_$i.jpg"];
                }, range(1, 10))
            ];
        }
    }
}

namespace {
    use AperturePro\Storage\ImageKitStorage;

    echo "Starting ImageKit existsMany benchmark...\n";

    $storage = new ImageKitStorage([
        'public_key' => 'test',
        'private_key' => 'test',
        'url_endpoint' => 'https://ik.imagekit.io/test'
    ]);

    $files = [];
    for ($i = 0; $i < 20; $i++) {
        $files[] = "/path/to/file_$i.jpg";
    }

    // Benchmark Sequential (N+1) - Simulating current implementation behavior if we hadn't changed it
    // We can't easily call the "old" method on the same object if we overwrite it,
    // but we can measure the new implementation.

    // To properly benchmark, one would run this before and after the code change.

    $start = microtime(true);
    $results = $storage->existsMany($files);
    $duration = microtime(true) - $start;

    echo "Checked " . count($files) . " files.\n";
    echo "Duration: " . number_format($duration, 4) . " seconds.\n";

    // In N+1 scenario (20 files * 50ms), expected ~1.0s
    // In Optimized scenario (1 or 2 calls * 50ms), expected ~0.05s - 0.1s
}

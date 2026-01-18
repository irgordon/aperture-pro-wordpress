<?php

// Mock classes
namespace ImageKit {
    class ImageKit {
        public $config;
        public function __construct($pk, $sk, $ep) {
            $this->config = [$pk, $sk, $ep];
        }
        // Default mock uses array signature (standard for many older SDKs or array-based ones)
        public function upload(array $params) {
            // Mock response
            $response = new \stdClass();
            $response->url = 'https://ik.imagekit.io/demo/' . ($params['fileName'] ?? 'file.jpg');
            $response->filePath = '/' . ($params['fileName'] ?? 'file.jpg');
            $response->folder = $params['folder'] ?? null;
            $response->tags = $params['tags'] ?? null;
            return $response;
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/Storage/UploadResult.php';
    require_once __DIR__ . '/../src/Storage/Retry/RetryExecutor.php';
    require_once __DIR__ . '/../src/Storage/Chunking/ChunkedUploader.php';
    require_once __DIR__ . '/../src/Storage/ImageKit/Capabilities.php';
    require_once __DIR__ . '/../src/Storage/ImageKit/ImageKitUploader.php';

    use AperturePro\Storage\ImageKit\ImageKitUploader;
    use AperturePro\Storage\Retry\RetryExecutor;
    use AperturePro\Storage\Chunking\ChunkedUploader;
    use AperturePro\Storage\ImageKit\Capabilities;
    use ImageKit\ImageKit;

    // Create a dummy file
    $dummyFile = sys_get_temp_dir() . '/test_ik_upload.txt';
    file_put_contents($dummyFile, 'dummy content');

    echo "---------------------------------------------------\n";
    echo "Verifying ImageKit Hardening (Stream/Chunk/Retry)\n";
    echo "---------------------------------------------------\n";

    // Test 1: Initialize
    echo "[1] Initializing ImageKitUploader...\n";
    $client = new ImageKit('pk', 'sk', 'https://ik.imagekit.io/demo');
    $retry = new RetryExecutor();
    $chunker = new ChunkedUploader(); // Default 5MB

    $uploader = new ImageKitUploader($client, $retry, $chunker);
    echo "PASS: Initialized.\n";

    // Test 2: Check Capabilities (should be false because our mock upload takes array params)
    echo "\n[2] Checking Capabilities (Fallback Scenario)...\n";
    $supportsStreams = Capabilities::supportsStreams($client);
    echo "Supports Streams: " . ($supportsStreams ? 'YES' : 'NO') . "\n";

    if (!$supportsStreams) {
        echo "PASS: Correctly detected NO stream support (will fallback to chunking).\n";
    } else {
        echo "FAIL: Expected NO streams support with default array signature.\n";
    }

    // Test 3: Upload (should trigger chunkedFallback)
    echo "\n[3] Testing Upload via Chunked Fallback...\n";
    try {
        $result = $uploader->upload($dummyFile, 'remote-test.txt');
        echo "Upload Result URL: " . $result->getUrl() . "\n";
        if (strpos($result->getUrl(), 'remote-test.txt') !== false) {
             echo "PASS: Upload successful via fallback.\n";
        } else {
             echo "FAIL: URL incorrect.\n";
        }
    } catch (\Throwable $e) {
        echo "FAIL: Upload threw exception: " . $e->getMessage() . "\n";
    }

    // Test 4: Test Capabilities with Stream Support Mock
    echo "\n[4] Testing Capabilities (Stream Support Scenario)...\n";

    // Define a class that extends ImageKit but has the 'file' parameter
    class MockClientWithFileParam extends ImageKit {
        public function upload($file, $fileName = null, $options = []) {
            // Simulate SDK that accepts array in first arg OR arguments, to satisfy both Capabilities check and usage
            $params = is_array($file) ? $file : ['file' => $file, 'fileName' => $fileName];

            $response = new \stdClass();
            $response->url = 'https://ik.imagekit.io/demo/' . ($params['fileName'] ?? 'streamed.jpg');
            $response->folder = $params['folder'] ?? null;
            $response->tags = $params['tags'] ?? null;
            return $response;
        }
    }

    // Reset static property in Capabilities
    $refProp = new ReflectionProperty(Capabilities::class, 'supportsStreams');
    $refProp->setAccessible(true);
    $refProp->setValue(null);

    $streamClient = new MockClientWithFileParam('pk', 'sk', 'ep');
    $supports = Capabilities::supportsStreams($streamClient);
    echo "Supports Streams (Mock): " . ($supports ? 'YES' : 'NO') . "\n";

    if ($supports) {
        echo "PASS: Correctly detected stream support.\n";
    } else {
        echo "FAIL: Expected YES streams support.\n";
    }

    // Test 5: Upload via Streaming
    echo "\n[5] Testing Upload via Streaming...\n";

    // Reset Capabilities again to be sure (it cached true from step 4, but let's be safe)
    $refProp->setValue(null);

    $streamUploader = new ImageKitUploader($streamClient, $retry, $chunker);
    try {
        $result = $streamUploader->upload($dummyFile, 'streamed-test.txt');
        echo "Upload Result URL: " . $result->getUrl() . "\n";
         if (strpos($result->getUrl(), 'streamed-test.txt') !== false) {
             echo "PASS: Upload successful via streaming.\n";
        } else {
             echo "FAIL: URL incorrect.\n";
        }
    } catch (\Throwable $e) {
        echo "FAIL: Streaming upload threw exception: " . $e->getMessage() . "\n";
    }

    // Test 6: Verify Folder and Tags
    echo "\n[6] Testing Folder and Tags...\n";
    try {
        $result = $streamUploader->upload($dummyFile, 'subfolder/tagged-file.jpg', ['tags' => ['foo', 'bar']]);
        $meta = $result->getMetadata();

        // Verify Folder
        if (isset($meta['folder']) && $meta['folder'] === 'subfolder') {
             echo "PASS: Folder passed correctly.\n";
        } else {
             echo "FAIL: Folder missing or incorrect. Got: " . ($meta['folder'] ?? 'null') . "\n";
        }

        // Verify Tags
        if (isset($meta['tags']) && $meta['tags'] === ['foo', 'bar']) {
             echo "PASS: Tags passed correctly.\n";
        } else {
             echo "FAIL: Tags missing or incorrect.\n";
        }

    } catch (\Throwable $e) {
        echo "FAIL: Upload threw exception: " . $e->getMessage() . "\n";
    }

    // Cleanup
    if (file_exists($dummyFile)) {
        unlink($dummyFile);
    }
}

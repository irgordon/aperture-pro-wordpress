<?php

namespace {
    // Mock WP functions
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
    }
    if (!function_exists('sanitize_file_name')) {
        function sanitize_file_name($name) {
            return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) { return $url; }
    }

    // Mock Logger
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class MockLogger {
            public static function log($level, $context, $message, $data = []) {}
        }
        class_alias('MockLogger', 'AperturePro\Helpers\Logger');
    }

    // Mock StorageInterface
    interface MockStorageInterface {
        public function getSignedUrl($path, $ttl);
        public function signMany($paths);
    }
}

namespace ZipStream\Option {
    class Archive {
        public function setSendHttpHeaders($val) {}
        public function setContentType($val) {}
        public function setEnableZip64($val) {}
    }
}

namespace ZipStream {
    class ZipStream {
        public function __construct($name, $options) {}
        public function addFileFromStream($name, $stream) {
            // Simulate reading from stream
            if (is_resource($stream)) {
                while (!feof($stream)) {
                    fread($stream, 8192);
                }
            }
        }
        public function finish() {}
    }
}

namespace AperturePro\Download {
    use ZipStream\ZipStream;
    use ZipStream\Option\Archive;

    class BenchmarkZipStream {
        public static function streamZip($files, $storage, $zipName) {
            $options = new \ZipStream\Option\Archive();
            $zip = new ZipStream($zipName, $options);

            foreach ($files as $file) {
                // Simulate individual signed URL generation latency
                $url = $storage->getSignedUrl($file['path'], 300);

                // Simulate network latency for fopen
                usleep(100000); // 100ms per file
                $stream = @fopen('php://temp', 'rb');

                if ($stream === false) {
                    continue;
                }

                $zip->addFileFromStream($file['name'], $stream);
                fclose($stream);
            }
            $zip->finish();
        }
    }

    // Optimized version (prototype for benchmark comparison)
    class OptimizedZipStream {
        public static function streamZip($files, $storage, $zipName) {
             $options = new \ZipStream\Option\Archive();
             $zip = new ZipStream($zipName, $options);

             $paths = array_column($files, 'path');
             $signedUrls = $storage->signMany($paths);

             $batchSize = 10;
             $chunks = array_chunk($files, $batchSize);

             foreach ($chunks as $chunk) {
                 $urlsToDownload = [];
                 foreach ($chunk as $file) {
                     $urlsToDownload[$file['path']] = $signedUrls[$file['path']] ?? $file['path'];
                 }

                 // Simulate parallel download
                 $tempFiles = self::mockParallelDownload($urlsToDownload);

                 foreach ($chunk as $file) {
                     $tempPath = $tempFiles[$file['path']] ?? null;
                     if ($tempPath && file_exists($tempPath)) {
                         $stream = fopen($tempPath, 'rb');
                         $zip->addFileFromStream($file['name'], $stream);
                         fclose($stream);
                         @unlink($tempPath);
                     }
                 }
             }

             $zip->finish();
        }

        private static function mockParallelDownload($urls) {
            $results = [];
            foreach ($urls as $path => $url) {
                $tmp = tempnam(sys_get_temp_dir(), 'zip-bench-');
                file_put_contents($tmp, str_repeat('a', 1024)); // 1KB of data
                $results[$path] = $tmp;
            }
            // Simulate that parallel download (batch) is much faster than sequential
            // 200ms for the whole batch of 10 instead of 10 * 100ms = 1s.
            usleep(200000);
            return $results;
        }
    }
}

namespace {
    class StorageMock implements MockStorageInterface {
        public function getSignedUrl($path, $ttl) {
            // Simulate network latency for EACH call to storage API
            usleep(20000); // 20ms
            return "php://temp";
        }
        public function signMany($paths) {
            // Simulate batch signing latency (faster than N individual)
            usleep(20000); // 20ms total
            $res = [];
            foreach ($paths as $p) $res[$p] = "php://temp";
            return $res;
        }
    }

    $files = [];
    for ($i=0; $i<20; $i++) {
        $files[] = ['path' => "file$i.jpg", 'name' => "file$i.jpg"];
    }

    $storage = new StorageMock();

    echo "--- Benchmarking ZipStream ---" . PHP_EOL;

    $start = microtime(true);
    AperturePro\Download\BenchmarkZipStream::streamZip($files, $storage, 'test.zip');
    $timeSequential = microtime(true) - $start;
    echo "Sequential Time: " . number_format($timeSequential, 4) . "s" . PHP_EOL;

    $start = microtime(true);
    AperturePro\Download\OptimizedZipStream::streamZip($files, $storage, 'test.zip');
    $timeOptimized = microtime(true) - $start;
    echo "Optimized Time: " . number_format($timeOptimized, 4) . "s" . PHP_EOL;

    $improvement = ($timeSequential - $timeOptimized) / $timeSequential * 100;
    echo "Improvement: " . number_format($improvement, 2) . "%" . PHP_EOL;
}

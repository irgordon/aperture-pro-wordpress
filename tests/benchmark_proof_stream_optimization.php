<?php

namespace AperturePro\Proof {
    // --- MOCKS ---

    // Global state for mocks
    $mock_streams = [];
    $mock_data = []; // content to serve
    $mock_ptr = [];  // current read position

    function reset_mocks() {
        global $mock_streams, $mock_data, $mock_ptr;
        $mock_streams = [];
        $mock_data = [];
        $mock_ptr = [];
    }

    function stream_socket_client($remote, &$errno, &$errstr, $timeout, $flags, $context) {
        global $mock_streams, $mock_data, $mock_ptr;
        // Create a dummy resource
        $fp = fopen('php://temp', 'w+');
        $id = (int)$fp;
        $mock_streams[$id] = $fp;
        $mock_ptr[$id] = 0;

        // Generate mock data: Large header, small body
        // 1MB of headers to make string concatenation painful
        $headers = "HTTP/1.1 200 OK\r\n";
        // Create many headers
        for ($i = 0; $i < 10000; $i++) {
            $headers .= "X-Custom-Header-$i: " . str_repeat('x', 90) . "\r\n";
        }
        $headers .= "\r\n";
        $body = "image_data";

        $mock_data[$id] = $headers . $body;

        return $fp;
    }

    function stream_set_blocking($stream, $mode) {
        return true;
    }

    function stream_select(&$read, &$write, &$except, $tv_sec, $tv_usec = 0) {
        // Always ready to read
        return count($read);
    }

    function fwrite($stream, $data) {
        // Mock writing request
        return strlen($data);
    }

    function fread($stream, $length) {
        global $mock_data, $mock_ptr;
        $id = (int)$stream;
        if (!isset($mock_data[$id])) return false;

        $content = $mock_data[$id];
        $pos = $mock_ptr[$id];

        if ($pos >= strlen($content)) {
            return ''; // EOF
        }

        // Return small chunks to force many loop iterations and concatenations
        // 1KB chunks
        $chunkSize = 1024;
        $chunk = substr($content, $pos, $chunkSize);
        $mock_ptr[$id] += strlen($chunk);

        return $chunk;
    }

    function feof($stream) {
        global $mock_data, $mock_ptr;
        $id = (int)$stream;
        if (!isset($mock_data[$id])) return true;
        return $mock_ptr[$id] >= strlen($mock_data[$id]);
    }

    function stream_context_create($options = []) {
        return null;
    }
}

namespace {
    // --- BENCHMARK ---

    // Mock WP functions
    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) {
            return tempnam(sys_get_temp_dir(), $prefix);
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return false; }
    }
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class MockLogger {
            public static function log($level, $context, $message, $data = []) {}
        }
        class_alias('MockLogger', 'AperturePro\Helpers\Logger');
    }

    // Include Source
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Proof\ProofService;

    // Define Legacy Class (The "Before" state)
    class LegacyProofService extends ProofService {

        public static function testParallel($urls) {
            return self::performParallelDownloadsStreams($urls);
        }

        protected static function performParallelDownloadsStreams(array $urls): array {
             $sockets = [];
             $files = [];
             $results = [];

             // 1. Initialize Sockets
             foreach ($urls as $key => $url) {
                $errno = 0; $errstr = '';
                // Call the namespaced mock
                $fp = \AperturePro\Proof\stream_socket_client($url, $errno, $errstr, 30, 0, null);
                if ($fp) {
                    $sockets[$key] = $fp;
                    $tmp = wp_tempnam('ap-proof-legacy-');
                    $files[$key] = [
                        'path' => $tmp,
                        'fp' => fopen($tmp, 'w+'),
                        'req' => "GET / HTTP/1.1\r\n\r\n",
                        'state' => 'connecting',
                        // BAD: Buffer string initialization
                        'buffer' => '',
                    ];
                }
             }

             // 2. Event Loop
             while (!empty($sockets)) {
                 $read = $sockets; $write = $sockets; $except = [];
                 if (\AperturePro\Proof\stream_select($read, $write, $except, 1) === false) break;

                 foreach ($read as $socket) {
                     $key = array_search($socket, $sockets, true);
                     if ($key !== false) {
                         // Call namespaced mock
                         $data = \AperturePro\Proof\fread($socket, 8192);

                         if ($data === false || ($data === '' && \AperturePro\Proof\feof($socket))) {
                             fclose($socket);
                             unset($sockets[$key]);
                         } elseif ($data !== '') {
                             if (!isset($files[$key]['headers_done'])) {
                                 // --- THE INEFFICIENT CODE ---
                                 $files[$key]['buffer'] .= $data;
                                 $pos = strpos($files[$key]['buffer'], "\r\n\r\n");

                                 if ($pos !== false) {
                                     // Headers done
                                     $body = substr($files[$key]['buffer'], $pos + 4);
                                     fwrite($files[$key]['fp'], $body);
                                     $files[$key]['headers_done'] = true;
                                     unset($files[$key]['buffer']);
                                 }
                             } else {
                                 fwrite($files[$key]['fp'], $data);
                             }
                         }
                     }
                 }
                 if (empty($read)) break;
             }

             // Cleanup
             foreach ($files as $file) {
                 if (is_resource($file['fp'])) fclose($file['fp']);
                 @unlink($file['path']);
             }

             return $results;
        }
    }

    // Expose Optimized for testing
    class OptimizedProofService extends ProofService {
        public static function testParallel($urls) {
            return self::performParallelDownloadsStreams($urls);
        }
    }

    echo "--- Benchmarking Stream Optimization ---\n";

    $urls = array_fill(0, 5, 'http://example.com/image.jpg'); // 5 parallel downloads

    // --- Test Optimized First ---
    \AperturePro\Proof\reset_mocks();
    gc_collect_cycles();
    $start = microtime(true);

    OptimizedProofService::testParallel($urls);

    $end = microtime(true);
    $peakOpt = memory_get_peak_usage();
    $timeOpt = $end - $start;

    echo "Optimized Peak Memory: " . number_format($peakOpt / 1024 / 1024, 2) . " MB\n";
    echo "Optimized Time: " . number_format($timeOpt, 4) . " s\n";


    // --- Test Legacy Second ---
    \AperturePro\Proof\reset_mocks();
    gc_collect_cycles();
    $start = microtime(true);

    LegacyProofService::testParallel($urls);

    $end = microtime(true);
    $peakLegacy = memory_get_peak_usage();
    $timeLegacy = $end - $start;

    echo "Legacy Peak Memory: " . number_format($peakLegacy / 1024 / 1024, 2) . " MB\n";
    echo "Legacy Time: " . number_format($timeLegacy, 4) . " s\n";

    $diff = $peakLegacy - $peakOpt;
    if ($diff > 0) {
        echo "PASS: Optimized version used less memory (Delta: " . number_format($diff / 1024 / 1024, 2) . " MB)\n";
    } else {
        echo "FAIL: No memory improvement detected (Delta: " . number_format($diff / 1024 / 1024, 2) . " MB)\n";
    }
}

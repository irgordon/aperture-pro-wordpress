<?php

namespace AperturePro\Proof {

// Mock State
$mock_sockets = []; // resource_id => ['response' => string, 'ptr' => int]
$curl_exec_calls = 0;

function reset_mocks() {
    global $mock_sockets, $curl_exec_calls;
    $mock_sockets = [];
    $curl_exec_calls = 0;
}

// Global Mocks for AperturePro\Proof namespace

function stream_socket_client($remote, &$errno, &$errstr, $timeout, $flags, $context) {
    global $mock_sockets;

    // Mock a socket resource using a temp file
    $fp = fopen('php://temp', 'w+');
    $id = (int)$fp;

    $mock_sockets[$id] = [
        'fp' => $fp,
        'response' => '', // Determined at fwrite time
        'ptr' => 0,
        'host' => $remote
    ];

    return $fp;
}

function stream_set_blocking($stream, $mode) { return true; }

function stream_select(&$read, &$write, &$except, $tv_sec, $tv_usec=0) {
    return count($read) + count($write);
}

function fwrite($stream, $data) {
    global $mock_sockets;
    $id = (int)$stream;

    if (isset($mock_sockets[$id])) {
        // Intercept request to determine response
        if (empty($mock_sockets[$id]['response'])) {
            if (strpos($data, 'redirect1') !== false) {
                 // Simulate 302 Relative
                 $mock_sockets[$id]['response'] = "HTTP/1.1 302 Found\r\nLocation: /final.jpg\r\n\r\n";
            } elseif (strpos($data, 'redirect') !== false) {
                 // Simulate 302 Absolute
                 $mock_sockets[$id]['response'] = "HTTP/1.1 302 Found\r\nLocation: http://example.com/final.jpg\r\n\r\n";
            } else {
                 // Simulate 200 OK (catch final.jpg or others)
                 $mock_sockets[$id]['response'] = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nIMAGE";
            }
        }
        return strlen($data);
    } else {
        return \fwrite($stream, $data);
    }
}

function fread($stream, $length) {
    global $mock_sockets;
    $id = (int)$stream;

    if (isset($mock_sockets[$id])) {
        $content = $mock_sockets[$id]['response'];
        $pos = $mock_sockets[$id]['ptr'];

        if ($pos >= strlen($content)) return '';

        $chunk = substr($content, $pos, $length);
        $mock_sockets[$id]['ptr'] += strlen($chunk);
        return $chunk;
    }

    return \fread($stream, $length);
}

function fclose($stream) {
    global $mock_sockets;
    $id = (int)$stream;
    if (isset($mock_sockets[$id])) {
        unset($mock_sockets[$id]);
        return \fclose($stream);
    }
    return \fclose($stream);
}

function feof($stream) {
    global $mock_sockets;
    $id = (int)$stream;
    if (isset($mock_sockets[$id])) {
        return $mock_sockets[$id]['ptr'] >= strlen($mock_sockets[$id]['response']);
    }
    return \feof($stream);
}

function stream_context_create($options = []) { return null; }

// Mock CURL for sequential fallback
function curl_init($url = null) { return 'curl_res'; }
function curl_setopt($ch, $opt, $val) {}
function curl_exec($ch) {
    global $curl_exec_calls;
    $curl_exec_calls++;
    usleep(1000000); // 1s latency
    return true;
}
function curl_getinfo($ch) { return ['http_code' => 200]; }
function curl_close($ch) {}
function curl_multi_init() { return false; }

// Mock WP
function wp_tempnam($prefix) { return tempnam(sys_get_temp_dir(), $prefix); }
function is_wp_error($t) { return false; }
function wp_remote_get($url, $args) { return ['response' => ['code' => 200]]; }
function wp_remote_retrieve_response_code($response) { return 200; }

// Logger Mock
class MockLogger {
    public static function log($level, $context, $message, $data = []) {}
}

} // End namespace AperturePro\Proof

namespace {
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class_alias('AperturePro\Proof\MockLogger', 'AperturePro\Helpers\Logger');
    }

    require_once __DIR__ . '/../src/Proof/ProofService.php';
    use AperturePro\Proof\ProofService;

    class TestableProofService extends ProofService {
        public static function runBenchmark($urls) {
             // Simulate "performParallelDownloads" when curl_multi is missing
             $results = [];

             // 1. Try Streams
             if (function_exists('AperturePro\Proof\stream_socket_client')) {
                $results = self::performParallelDownloadsStreams($urls);
             }

             // 2. Fallback
             $remaining = array_diff_key($urls, $results);

             if (!empty($remaining)) {
                 $ch = \AperturePro\Proof\curl_init();
                 foreach ($remaining as $key => $url) {
                     $tmp = \AperturePro\Proof\wp_tempnam('ap-proof-');
                     $fp = fopen($tmp, 'w+');

                     \AperturePro\Proof\curl_setopt($ch, 0, $url);
                     \AperturePro\Proof\curl_exec($ch);

                     fclose($fp);
                     $results[$key] = $tmp;
                 }
                 \AperturePro\Proof\curl_close($ch);
             }

             return $results;
        }
    }

    echo "--- Benchmarking Parallel Fallback ---\n";

    $urls = [
        'ok' => 'http://example.com/image.jpg',
        'r1' => 'http://example.com/redirect1.jpg',
        'r2' => 'http://example.com/redirect2.jpg',
    ];

    $start = microtime(true);

    $results = TestableProofService::runBenchmark($urls);

    $end = microtime(true);

    echo "Time: " . number_format($end - $start, 4) . " s\n";
    echo "Results: " . count($results) . "/" . count($urls) . "\n";
    echo "Sequential CURL Calls: " . $GLOBALS['curl_exec_calls'] . "\n";

    // Cleanup
    foreach ($results as $f) @unlink($f);
}

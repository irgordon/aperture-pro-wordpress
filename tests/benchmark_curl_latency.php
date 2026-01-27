<?php

namespace AperturePro\Proof {

    // MOCKING CURL FUNCTIONS IN NAMESPACE
    // This allows us to simulate the "select returns -1" behavior and measure the impact of usleep.

    global $mock_curl_runs, $mock_curl_max_runs;
    $mock_curl_runs = 0;
    $mock_curl_max_runs = 50;

    function curl_multi_init() { return 'mh_mock'; }
    function curl_init($url = null) { return 'ch_mock'; }
    function curl_setopt($ch, $opt, $val) {}
    function curl_multi_add_handle($mh, $ch) {}
    function curl_multi_remove_handle($mh, $ch) {}
    function curl_close($ch) {}
    function curl_multi_close($mh) {}
    function curl_getinfo($ch) { return ['http_code' => 200]; }
    function curl_error($ch) { return ''; }

    function curl_multi_exec($mh, &$active) {
        global $mock_curl_runs, $mock_curl_max_runs;

        // Simulate work being done in steps
        $mock_curl_runs++;

        if ($mock_curl_runs < $mock_curl_max_runs) {
            $active = 1;
        } else {
            $active = 0;
        }

        // Occasionally return CALL_MULTI_PERFORM to exercise that inner loop
        if ($mock_curl_runs % 5 == 0) {
            return CURLM_CALL_MULTI_PERFORM;
        }

        return CURLM_OK;
    }

    function curl_multi_select($mh) {
        // ALWAYS return -1 to simulate the condition that triggers the usleep
        return -1;
    }
}

namespace {
    // Global mocks
    if (!defined('ABSPATH')) define('ABSPATH', '/tmp');
    if (!defined('APERTURE_PRO_FILE')) define('APERTURE_PRO_FILE', '/tmp/aperture.php');

    if (!function_exists('wp_tempnam')) {
        function wp_tempnam($prefix) { return tempnam(sys_get_temp_dir(), $prefix); }
    }

    class MockStorage {
    }

    // Mock Logger
    class Logger {
        public static function log($l, $c, $m, $ctx=[]) {}
    }
    class_alias('Logger', 'AperturePro\Helpers\Logger');

    // Load ProofService
    require_once __DIR__ . '/../src/Proof/ProofService.php';

    use AperturePro\Proof\ProofService;

    // Use Reflection to access protected method
    $method = new \ReflectionMethod(ProofService::class, 'performParallelDownloads');
    $method->setAccessible(true);

    echo "Running Benchmark: ProofService::performParallelDownloads with mocked curl_multi_select = -1\n";

    $urls = [
        'img1' => 'http://example.com/1.jpg',
        'img2' => 'http://example.com/2.jpg',
        'img3' => 'http://example.com/3.jpg',
    ];

    $start = microtime(true);

    $results = $method->invoke(null, $urls);

    $end = microtime(true);
    $duration = $end - $start;

    global $mock_curl_runs;

    echo "Time taken: " . number_format($duration, 4) . "s\n";
    echo "This includes ~" . $mock_curl_runs . " iterations (simulated).\n"; // Just checking

    // If usleep(5000) is called in each iteration (roughly), we expect ~40 * 5ms = 200ms delay.
    // (mock_curl_runs goes up to 50)
}

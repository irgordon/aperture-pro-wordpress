<?php

namespace {
    // Mock WordPress constants
    if (!defined('AUTH_KEY')) define('AUTH_KEY', 'test_auth_key');
    if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', 'test_secure_auth_key');
    if (!defined('LOGGED_IN_KEY')) define('LOGGED_IN_KEY', 'test_logged_in_key');

    // Mock WordPress functions
    if (!function_exists('get_option')) {
        function get_option($key, $default = false) {
            if ($key === 'siteurl') return 'http://example.com';
            return $default;
        }
    }

    // Mock Global $wpdb
    class MockWPDB {
        public $prefix = 'wp_';
    }
    global $wpdb;
    $wpdb = new MockWPDB();
}

namespace AperturePro\Tests {
    require_once __DIR__ . '/../src/Helpers/Crypto.php';

    use AperturePro\Helpers\Crypto;

    function benchmark_crypto_derive_key(int $iterations = 100000): void
    {
        echo "Benchmarking Crypto::deriveKey via Crypto::encrypt ({$iterations} iterations)\n";

        $plaintext = 'Secret Message';

        // Warm up
        Crypto::encrypt($plaintext);

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Crypto::encrypt($plaintext);
        }
        $duration = microtime(true) - $start;

        echo "Time taken: {$duration} seconds\n";
        echo "Avg time per call: " . ($duration / $iterations * 1000000) . " microseconds\n";
    }

    benchmark_crypto_derive_key();
}

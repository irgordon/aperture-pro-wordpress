<?php

namespace AperturePro\Helpers {
    if (!class_exists('AperturePro\Helpers\Logger')) {
        class Logger {
            public static function log($level, $context, $message, $meta = []) {}
        }
    }
}

namespace {
    // Mock WP functions
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            return [
                'basedir' => '/tmp/wp-content/uploads',
                'baseurl' => 'http://example.com/wp-content/uploads',
            ];
        }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit($path) {
            return rtrim($path, '/') . '/';
        }
    }
    // set_transient is no longer used by LocalStorage, but we keep it to ensure it's NOT called if we were tracking calls.
    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) {
            // This should not be called anymore.
            // If it is called, it means we failed to remove the DB dependency.
            trigger_error("set_transient called!", E_USER_WARNING);
            usleep(1000);
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient($transient) {
            return false;
        }
    }
    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p($path) {
            return true;
        }
    }
    if (!function_exists('set_url_scheme')) {
        function set_url_scheme($url, $scheme = null) {
            return $url;
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) {
            return $url;
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url($path = '') {
            return 'http://example.com/wp-json/' . ltrim($path, '/');
        }
    }
    if (!function_exists('size_format')) {
        function size_format($bytes, $decimals = 0) {
            return $bytes . ' B';
        }
    }
    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth') {
            return 'benchmark_salt_key_12345';
        }
    }
    if (!function_exists('hash_equals')) {
        function hash_equals($known_string, $user_string) {
            return hash_hmac('sha256', 'a', 'b') === hash_hmac('sha256', 'a', 'b') && $known_string === $user_string; // naive shim if needed (PHP 5.6+)
        }
    }

    require_once __DIR__ . '/../src/Storage/StorageInterface.php';
    require_once __DIR__ . '/../src/Storage/AbstractStorage.php';
    require_once __DIR__ . '/../src/Storage/Traits/Retryable.php';
    require_once __DIR__ . '/../src/Storage/LocalStorage.php';

    $storage = new \AperturePro\Storage\LocalStorage(['path' => 'aperture-pro/']);

    $start = microtime(true);
    $iterations = 1000;
    $lastToken = '';
    $lastPath = "image_{$iterations}.jpg";

    for ($i = 0; $i < $iterations; $i++) {
        // getUrl creates the token internally.
        // But getUrl returns a URL.
        // We can't easily extract the token from URL without regex,
        // but let's just measure generation time first.
        $url = $storage->getUrl("image_{$i}.jpg");
        if ($i === $iterations - 1) {
            // Extract token from URL for verification test
            // URL format: http://example.com/wp-json/aperture/v1/local-file/TOKEN
            if (preg_match('/local-file\/(.+)$/', $url, $matches)) {
                $lastToken = $matches[1];
            }
        }
    }

    $end = microtime(true);
    $duration = $end - $start;

    echo "Generated {$iterations} tokens in " . number_format($duration, 4) . " seconds.\n";
    echo "Average per token: " . number_format(($duration / $iterations) * 1000, 4) . " ms\n";

    // Verification Test
    echo "\nVerifying last token...\n";
    $payload = $storage->verifyToken($lastToken);

    if ($payload && isset($payload['key']) && $payload['key'] === "image_" . ($iterations - 1) . ".jpg") {
        echo "SUCCESS: Token verified correctly. Key: " . $payload['key'] . "\n";
        echo "Payload Path: " . $payload['path'] . "\n";
    } else {
        echo "FAILURE: Token verification failed.\n";
        print_r($payload);
    }
}

<?php

namespace {
    // Mock WordPress environment
    $mock_options = [];
    $update_option_calls = [];

    if (!function_exists('get_option')) {
        function get_option($key, $default = false) {
            global $mock_options;
            if ($key === 'siteurl') return 'http://example.com';
            return $mock_options[$key] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($key, $value, $autoload = null) {
            global $mock_options, $update_option_calls;
            $mock_options[$key] = $value;
            $update_option_calls[] = ['key' => $key, 'value' => $value];
            return true;
        }
    }
}

namespace AperturePro\Tests {
    require_once __DIR__ . '/../src/Helpers/Crypto.php';

    use AperturePro\Helpers\Crypto;
    use ReflectionClass;

    function verify_crypto_fallback() {
        global $mock_options, $update_option_calls;
        echo "Verifying Crypto secure fallback behavior...\n";

        // 1. First run: No constants, no stored salt.
        // Should generate salt and store it.

        $reflector = new ReflectionClass(Crypto::class);
        $property = $reflector->getProperty('_key');
        $property->setAccessible(true);
        $property->setValue(null);

        $method = $reflector->getMethod('deriveKey');
        $method->setAccessible(true);
        $key1 = $method->invoke(null);

        // Verify update_option was called
        $generatedSalt = null;
        $found = false;
        foreach ($update_option_calls as $call) {
            if ($call['key'] === 'aperture_generated_salt') {
                $generatedSalt = $call['value'];
                $found = true;
                break;
            }
        }

        if ($found) {
            echo "PASS: update_option('aperture_generated_salt') was called.\n";
            echo "Generated Salt: " . $generatedSalt . "\n";
        } else {
            echo "FAIL: update_option('aperture_generated_salt') was NOT called.\n";
        }

        // Verify the key matches the generated salt
        $expectedKey1 = hash('sha256', $generatedSalt, true);
        if ($key1 === $expectedKey1) {
            echo "PASS: Derived key matches the generated salt.\n";
        } else {
            echo "FAIL: Derived key does not match generated salt.\n";
        }

        // 2. Second run: No constants, salt is stored.
        // Should retrieve existing salt.

        // Clear cached static key
        $property->setValue(null);

        // Reset update_option calls to ensure we don't write again
        $update_option_calls = [];

        $key2 = $method->invoke(null);

        if ($key2 === $key1) {
            echo "PASS: Second call derived the same key (persistence working).\n";
        } else {
            echo "FAIL: Second call derived a DIFFERENT key.\n";
        }

        if (empty($update_option_calls)) {
             echo "PASS: update_option was NOT called on second run.\n";
        } else {
             echo "FAIL: update_option WAS called on second run (should be cached in options).\n";
        }
    }

    verify_crypto_fallback();
}

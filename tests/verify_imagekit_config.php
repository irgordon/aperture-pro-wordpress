<?php

namespace {
    // Mock WordPress functions
    $mock_options = [];

    function get_option($key, $default = []) {
        global $mock_options;
        return $mock_options[$key] ?? $default;
    }

    function update_option($key, $value) {
        global $mock_options;
        $mock_options[$key] = $value;
        return true;
    }

    function sanitize_text_field($str) {
        return trim($str);
    }
}

namespace AperturePro\Config {
    require_once __DIR__ . '/../src/Config/Config.php';

    // Setup mock data with new ImageKit fields
    global $mock_options;
    $mock_options['aperture_pro_settings'] = [
        'imagekit_public_key' => 'ik_public_123',
        'imagekit_private_key' => 'ik_private_encrypted',
        'imagekit_url_endpoint' => 'https://ik.imagekit.io/demo',
    ];

    echo "--- Testing ImageKit Config Changes ---\n";

    // Call Config::all()
    $config = Config::all();

    // Check ImageKit config
    $imagekit = $config['storage']['imagekit'] ?? [];

    echo "ImageKit Config:\n";
    print_r($imagekit);

    $missing = [];
    if (!isset($imagekit['public_key'])) {
        $missing[] = 'public_key';
    }
    if (!isset($imagekit['private_key'])) {
        $missing[] = 'private_key';
    }
    if (!isset($imagekit['url_endpoint'])) {
        $missing[] = 'url_endpoint';
    }

    if (empty($missing)) {
        echo "PASS: All required ImageKit fields are present.\n";

        // Verify values
        if ($imagekit['public_key'] === 'ik_public_123' &&
            $imagekit['private_key'] === 'ik_private_encrypted' &&
            $imagekit['url_endpoint'] === 'https://ik.imagekit.io/demo') {
             echo "PASS: Values match expected mock data.\n";
        } else {
             echo "FAIL: Values do not match.\n";
        }

    } else {
        echo "FAIL: Missing fields: " . implode(', ', $missing) . "\n";
    }
}

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
}

namespace AperturePro\Config {
    require_once __DIR__ . '/../src/Config/Config.php';

    // Setup mock data with new fields
    global $mock_options;
    $mock_options['aperture_pro_settings'] = [
        'cloud_api_key' => 'encrypted_key_123',
        'cloudinary_cloud_name' => 'my-cloud-name',
        'cloudinary_api_secret' => 'encrypted_secret_456',
    ];

    echo "--- Testing Config Changes ---\n";

    // Call Config::all()
    $config = Config::all();

    // Check Cloudinary config
    $cloudinary = $config['storage']['cloudinary'] ?? [];

    echo "Cloudinary Config:\n";
    print_r($cloudinary);

    $missing = [];
    if (!isset($cloudinary['cloud_name'])) {
        $missing[] = 'cloud_name';
    }
    if (!isset($cloudinary['api_secret'])) {
        $missing[] = 'api_secret';
    }

    if (empty($missing)) {
        echo "PASS: All required Cloudinary fields are present.\n";

        // Verify values
        if ($cloudinary['cloud_name'] === 'my-cloud-name' && $cloudinary['api_secret'] === 'encrypted_secret_456') {
             echo "PASS: Values match expected mock data.\n";
        } else {
             echo "FAIL: Values do not match.\n";
        }

    } else {
        echo "FAIL: Missing fields: " . implode(', ', $missing) . "\n";
    }
}

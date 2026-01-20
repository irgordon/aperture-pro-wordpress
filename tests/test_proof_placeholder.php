<?php

// Mock Constants
define('APERTURE_PRO_FILE', __FILE__);

// Mock WordPress Functions
$mock_options = [];

function get_option($option, $default = false) {
    global $mock_options;
    return $mock_options[$option] ?? $default;
}

function update_option($option, $value) {
    global $mock_options;
    $mock_options[$option] = $value;
}

function plugins_url($path = '', $plugin = '') {
    return 'http://example.com/wp-content/plugins/aperture-pro/' . $path;
}

function apply_filters($tag, $value) {
    global $mock_filters;
    if (isset($mock_filters[$tag])) {
        return call_user_func($mock_filters[$tag], $value);
    }
    return $value;
}

// Mock Dependencies
namespace AperturePro\Storage;
interface StorageInterface {}
class StorageFactory {}

namespace AperturePro\Helpers;
class Logger {}

namespace AperturePro\Proof;
class ProofCache {}
class ProofQueue {}

// Load Classes
require_once __DIR__ . '/../src/Config/Config.php';
require_once __DIR__ . '/../src/Proof/ProofService.php';

use AperturePro\Config\Config;
use AperturePro\Proof\ProofService;

// --- Test 1: Default Behavior ---
echo "Test 1: Default Behavior\n";
global $mock_options;
$mock_options = []; // Clear options
// Force Config cache clear (Config::set invalidates cache)
Config::set('dummy', 'value');

$url = ProofService::getPlaceholderUrl();
echo "URL: $url\n";

if ($url === 'http://example.com/wp-content/plugins/aperture-pro/assets/images/processing-proof.svg') {
    echo "PASS\n";
} else {
    echo "FAIL: Expected default URL\n";
}

// --- Test 2: Custom Config ---
echo "\nTest 2: Custom Config\n";
$customUrl = 'http://mysite.com/my-placeholder.jpg';
$mock_options['aperture_pro_settings'] = [
    'custom_placeholder_url' => $customUrl
];
// Force Config cache clear
Config::set('dummy', 'value');

$url = ProofService::getPlaceholderUrl();
echo "URL: $url\n";

if ($url === $customUrl) {
    echo "PASS\n";
} else {
    echo "FAIL: Expected custom URL\n";
}

// --- Test 3: Filter Override ---
echo "\nTest 3: Filter Override\n";
global $mock_filters;
$filterUrl = 'http://filter-override.com/image.png';
$mock_filters['aperture_pro_proof_placeholder_url'] = function($url) use ($filterUrl) {
    return $filterUrl;
};

$url = ProofService::getPlaceholderUrl();
echo "URL: $url\n";

if ($url === $filterUrl) {
    echo "PASS\n";
} else {
    echo "FAIL: Expected filter override\n";
}

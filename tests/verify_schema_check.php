<?php
// tests/verify_schema_check.php

$mock_update_option_called = false;

// Mocks
class MockWPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'utf8mb4_general_ci'; }
    public function query($q) { return true; }
    public function get_var($q) { return 0; }
    public function prepare($q, ...$args) { return $q; }
    public function get_row($q, $type = OBJECT) { return []; }
}
global $wpdb;
$wpdb = new MockWPDB();

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if ($option === 'aperture_pro_db_version') return '0.0.0';
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_update_option_called;
        if ($option === 'aperture_pro_db_version') {
            $mock_update_option_called = true;
        }
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {}
}

if (!function_exists('is_admin')) {
    function is_admin() { global $mock_is_admin; return $mock_is_admin ?? false; }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { global $mock_doing_ajax; return $mock_doing_ajax ?? false; }
}
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() { global $mock_doing_cron; return $mock_doing_cron ?? false; }
}

define('ABSPATH', __DIR__ . '/../mock_wp/');
require_once __DIR__ . '/../src/Installer/Schema.php';
use AperturePro\Installer\Schema;

// Test 1: Admin Request -> Should Upgrade
echo "Test 1: Admin Request...\n";
$mock_is_admin = true;
$mock_update_option_called = false;

ob_start();
Schema::maybe_upgrade();
$output = ob_get_clean();

if ($mock_update_option_called && strpos($output, 'Creating table') !== false) {
    echo "PASS: Upgrade ran in admin.\n";
} else {
    echo "FAIL: Upgrade did not run in admin.\n";
    echo "Output: $output\n";
    echo "Update option called: " . ($mock_update_option_called ? 'yes' : 'no') . "\n";
    exit(1);
}

// Test 2: Frontend Request -> Should Skip
echo "Test 2: Frontend Request...\n";
$mock_is_admin = false;
$mock_doing_ajax = false;
$mock_doing_cron = false;
// Reset flags
$mock_update_option_called = false;

ob_start();
Schema::maybe_upgrade();
$output = ob_get_clean();

if (!$mock_update_option_called && empty($output)) {
    echo "PASS: Upgrade skipped in frontend.\n";
} else {
    echo "FAIL: Upgrade ran in frontend (should have skipped).\n";
    echo "Output: $output\n";
    exit(1);
}

// Test 3: AJAX Request -> Should Upgrade
echo "Test 3: AJAX Request...\n";
$mock_is_admin = false;
$mock_doing_ajax = true;
$mock_update_option_called = false;

ob_start();
Schema::maybe_upgrade();
$output = ob_get_clean();

// Note: dbDelta is already defined, so it will output if called
if ($mock_update_option_called && strpos($output, 'Creating table') !== false) {
    echo "PASS: Upgrade ran during AJAX.\n";
} else {
    echo "FAIL: Upgrade did not run during AJAX.\n";
    exit(1);
}

echo "All verification tests passed.\n";

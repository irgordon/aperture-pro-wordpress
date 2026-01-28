<?php
// tests/verify_full_install.php

// Mock WordPress environment
define('ABSPATH', __DIR__ . '/../mock_wp/');
define('WP_DEBUG', true);

// Mock WP functions
$options = [];
function get_option($option, $default = false) {
    global $options;
    return $options[$option] ?? $default;
}
function update_option($option, $value, $autoload = null) {
    global $options;
    $options[$option] = $value;
    return true;
}
function set_transient($transient, $value, $expiration) {}
function get_transient($transient) { return false; }
function delete_transient($transient) {}
function current_user_can($capability) { return true; }
function plugin_dir_path($file) { return __DIR__ . '/../'; }
function plugin_dir_url($file) { return 'http://example.com/wp-content/plugins/aperture-pro/'; }
function wp_safe_redirect($location) {}
function admin_url($path = '') { return 'http://example.com/wp-admin/' . $path; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_admin() { return true; }
function wp_json_encode($data) { return json_encode($data); }
function current_time($type) { return date('Y-m-d H:i:s'); }
function esc_html($s) { return $s; }
function sanitize_text_field($s) { return trim($s); }
function maybe_unserialize($d) { return $d; }
function is_serialized($d) { return false; }
function wp_next_scheduled($hook) { return false; }

// Mock get_plugin_data to avoid file include error
function get_plugin_data($file) {
    return ['Version' => '1.0.86'];
}

// Mock DB
class MockWPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'utf8mb4_general_ci'; }
    public function query($q) { return true; }
    public function get_var($q) { return 0; } // Return 0 to simulate fresh install (table count = 0)
    public function prepare($q, ...$args) { return $q; }
    public function get_row($q, $type = OBJECT) { return []; }
    public function esc_like($s) { return $s; }
}
global $wpdb;
$wpdb = new MockWPDB();

// Hooks registry
$actions = [];
function add_action($hook, $callback, $priority = 10) {
    global $actions;
    $actions[$hook][] = $callback;
}
function register_activation_hook($file, $callback) {
    global $activation_callback;
    $activation_callback = $callback;
}
function register_deactivation_hook($file, $callback) {}
function register_uninstall_hook($file, $callback) {}

// Load the plugin bootstrap file
require_once __DIR__ . '/../aperture-pro.php';

// Simulate Activation
echo "Simulating activation...\n";
if (isset($activation_callback) && is_callable($activation_callback)) {
    $activation_callback();
} else {
    echo "FAIL: Activation callback not registered.\n";
    exit(1);
}

// Check if config options are set
$config_ok = false;
if (isset($options['aperture_pro_settings']) && !empty($options['aperture_pro_settings'])) {
    echo "PASS: Configuration initialized.\n";
    print_r($options['aperture_pro_settings']);
    $config_ok = true;
} else {
    echo "FAIL: Configuration NOT initialized.\n";
}

// Check version
$version_ok = false;
if (isset($options['aperture_pro_version']) && $options['aperture_pro_version'] === '1.0.86') {
    echo "PASS: Version set to 1.0.86.\n";
    $version_ok = true;
} else {
    echo "FAIL: Version NOT set correctly.\n";
    print_r($options['aperture_pro_version'] ?? 'NOT SET');
}

if ($config_ok && $version_ok) {
    echo "ALL TESTS PASSED.\n";
} else {
    exit(1);
}

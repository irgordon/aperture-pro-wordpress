<?php

// Mock WordPress functions
if (!function_exists('add_action')) { function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {} }
if (!function_exists('add_filter')) { function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {} }
if (!function_exists('register_setting')) { function register_setting($option_group, $option_name, $args = []) {} }
if (!function_exists('add_settings_section')) { function add_settings_section($id, $title, $callback, $page) {} }
if (!function_exists('add_settings_field')) { function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = []) {} }
if (!function_exists('get_current_screen')) { function get_current_screen() { return null; } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url($file) { return ''; } }
if (!function_exists('admin_url')) { function admin_url($path = '', $scheme = 'admin') { return ''; } }
if (!function_exists('rest_url')) { function rest_url($path = '', $scheme = 'rest') { return ''; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($action = -1) { return 'nonce'; } }
if (!function_exists('check_ajax_referer')) { function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return trim($str); } }
if (!function_exists('current_user_can')) { function current_user_can($capability, ...$args) { return true; } }
if (!function_exists('get_option')) { function get_option($option, $default = false) { return $default; } }
if (!function_exists('esc_url')) { function esc_url($url, $protocols = null, $_context = 'display') { return $url; } }
if (!function_exists('esc_attr')) { function esc_attr($text) { return $text; } }
if (!function_exists('sanitize_email')) { function sanitize_email($email) { return $email; } }
if (!function_exists('selected')) { function selected($selected, $current = true, $echo = true) {} }
if (!function_exists('checked')) { function checked($checked, $current = true, $echo = true) {} }
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null, $options = 0) {
        echo "SUCCESS: " . json_encode($data) . "\n";
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null, $options = 0) {
        echo "ERROR: " . json_encode($data) . "\n";
    }
}
if (!function_exists('esc_url_raw')) { function esc_url_raw($url) { return $url; } }


// Mock Logger
namespace AperturePro\Helpers;

class Logger {
    public static function log($level, $context, $message, $meta = []) {
        // No-op for benchmark
    }
}

// Mock Crypto (referenced in AdminUI)
class Crypto {
    public static function encrypt($text) { return $text; }
    public static function decrypt($text) { return $text; }
}

namespace AperturePro\Admin;

require_once __DIR__ . '/../src/Admin/AdminUI.php';

// Setup Test Data
$_POST['key'] = 'sk_test_123456789';
$_POST['provider'] = 'stripe';

echo "Starting benchmark...\n";
$start = microtime(true);

AdminUI::ajax_test_api_key();

$end = microtime(true);
$duration = $end - $start;

echo "Duration: " . number_format($duration, 4) . " seconds\n";

<?php
// Mock WordPress environment

define('ABSPATH', __DIR__ . '/../');

// Helper to track calls
$calls = [
    'add_action' => [],
    'wp_enqueue_style' => [],
    'wp_enqueue_script' => [],
    'block_template_part' => []
];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    global $calls;
    $calls['add_action'][] = $hook;
    if ($hook === 'wp_enqueue_scripts' && is_callable($callback)) {
        $callback();
    }
}

function add_theme_support($feature) {}
function add_editor_style($style) {}
function get_theme_file_path($file) { return ABSPATH . 'aperture-pro-theme/' . $file; }
function register_nav_menus($menus) {}
function remove_action($tag, $function_to_remove, $priority = 10) {}
function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {}

class MockTheme {
    public function get($key) { return '1.0.0'; }
}
function wp_get_theme() { return new MockTheme(); }

function get_theme_file_uri($file = '') { return 'http://example.com/wp-content/themes/aperture-pro-theme/' . $file; }
function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
    global $calls;
    $calls['wp_enqueue_style'][] = $handle;
}
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
    global $calls;
    $calls['wp_enqueue_script'][] = $handle;
}
function wp_localize_script($handle, $object_name, $l10n) {}
function admin_url($path = '') { return 'http://example.com/wp-admin/' . $path; }
function wp_create_nonce($action = -1) { return 'nonce'; }
function get_option($option, $default = false) { return $default; }
function esc_url($url) { return $url; }
function wp_kses_post($data) { return $data; }

function block_template_part($part) {
    global $calls;
    $calls['block_template_part'][] = $part;
    echo "TEMPLATE PART: $part";
}

function __($text, $domain = 'default') { return $text; }

// Test execution

echo "Loading functions.php...\n";
require_once ABSPATH . 'aperture-pro-theme/functions.php';

echo "Loading header.php...\n";
ob_start();
require ABSPATH . 'aperture-pro-theme/header.php';
$header_output = ob_get_clean();
echo "Header Output: " . trim($header_output) . "\n";

echo "Loading footer.php...\n";
ob_start();
require ABSPATH . 'aperture-pro-theme/footer.php';
$footer_output = ob_get_clean();
echo "Footer Output: " . trim($footer_output) . "\n";

// Verify enqueues
echo "Verifying enqueues...\n";
$required_styles = ['aperture-theme-header', 'aperture-theme-navigation'];
foreach ($required_styles as $style) {
    if (in_array($style, $calls['wp_enqueue_style'])) {
        echo "SUCCESS: Style '$style' enqueued.\n";
    } else {
        echo "FAILURE: Style '$style' NOT enqueued.\n";
        exit(1);
    }
}

// Verify template parts
if (in_array('header', $calls['block_template_part'])) {
    echo "SUCCESS: Header block template part called.\n";
} else {
    echo "FAILURE: Header block template part NOT called.\n";
    exit(1);
}

if (in_array('footer', $calls['block_template_part'])) {
    echo "SUCCESS: Footer block template part called.\n";
} else {
    echo "FAILURE: Footer block template part NOT called.\n";
    exit(1);
}

echo "All tests passed!\n";

<?php
// tests/verify_schema_update.php

define('ABSPATH', __DIR__ . '/../mock_wp/');
if (!file_exists(ABSPATH . 'wp-admin/includes/')) {
    mkdir(ABSPATH . 'wp-admin/includes/', 0777, true);
}
// Mock dbDelta to print the table name being created
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', '<?php function dbDelta($sql) {
    if (preg_match("/CREATE TABLE (\S+)/", $sql, $matches)) {
        echo "Creating table: " . $matches[1] . "\n";
        if (strpos($sql, "payment_status") !== false) {
             echo "  - Found column: payment_status\n";
        }
        if (strpos($sql, "ap_payment_events") !== false) {
             echo "  - Found table: ap_payment_events\n";
        }
        if (strpos($sql, "ap_email_queue") !== false) {
             echo "  - Found table: ap_email_queue\n";
        }
    }
} ?>');

// Mock WP functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return '0.0.0'; // Force upgrade
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}
if (!function_exists('version_compare')) {
    // Standard php version_compare
}

class MockWPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function get_var($query) { return null; }
    public function get_row($query) { return null; }
    public function prepare($query, ...$args) { return $query; }
    public function query($query) { return true; }
}

global $wpdb;
$wpdb = new MockWPDB();

require_once __DIR__ . '/../src/Installer/Schema.php';

echo "Running Schema::activate()...\n";
AperturePro\Installer\Schema::activate();
echo "Done.\n";

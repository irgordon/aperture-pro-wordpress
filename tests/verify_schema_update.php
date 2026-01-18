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
    }
} ?>');

class MockWPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
}

global $wpdb;
$wpdb = new MockWPDB();

require_once __DIR__ . '/../src/Installer/Schema.php';

echo "Running Schema::createTables()...\n";
AperturePro\Installer\Schema::createTables();
echo "Done.\n";

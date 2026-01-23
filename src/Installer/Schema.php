<?php
declare(strict_types=1);

namespace AperturePro\Installer;

final class Schema
{
    public const DB_VERSION_OPTION = 'aperture_pro_db_version';

    /**
     * Bump this when schema changes.
     * Keep it aligned with your release tags when schema changes ship.
     */
    public const DB_VERSION = '1.0.12';

    /**
     * Run on activation and on every request (cheap) to ensure schema is current.
     */
    public static function maybe_upgrade(): void
    {
        $installed = (string) get_option(self::DB_VERSION_OPTION, '0.0.0');

        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::upgrade_from($installed);

        // Clear object cache on version upgrade to invalidate old signed URLs if schema/logic changes
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
    }

    /**
     * Activation path: always ensure tables exist and migrations applied.
     */
    public static function activate(): void
    {
        self::maybe_upgrade();
    }

    private static function upgrade_from(string $from): void
    {
        // New installs or very old installs should get core tables first.
        self::create_core_tables();

        // Incremental migrations. Add new blocks as versions advance.
        if (version_compare($from, '1.0.9', '<')) {
            self::migrate_to_109_payments();
        }

        // Ensure FK constraints last (optional and safe).
        self::ensure_payment_foreign_keys();
    }

    private static function create_core_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        /**
         * IMPORTANT:
         * For dbDelta to work well, define the *full* authoritative table schema here
         * (including current columns). dbDelta will create on new installs.
         */
        $projects = "
            CREATE TABLE {$p}ap_projects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'lead',

                session_date DATETIME NULL,
                eta_delivery DATETIME NULL,

                -- Payment fields (current schema)
                payment_provider VARCHAR(50) NULL,
                payment_intent_id VARCHAR(100) NULL,
                payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                payment_amount DECIMAL(10,2) NULL,
                payment_currency VARCHAR(10) NULL,
                payment_last_update DATETIME NULL,
                booking_date DATETIME NULL,
                package_price DECIMAL(10,2) NULL,

                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,

                PRIMARY KEY  (id),
                KEY client_id (client_id),
                KEY status (status),
                KEY payment_status (payment_status)
            ) {$charset};
        ";

        dbDelta($projects);

        // ap_clients
        $clients = "
            CREATE TABLE {$p}ap_clients (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(50) NULL,
                validated_email_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY email (email)
            ) {$charset};
        ";
        dbDelta($clients);

        // ap_galleries
        $galleries = "
            CREATE TABLE {$p}ap_galleries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'uploaded',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY project_id (project_id)
            ) {$charset};
        ";
        dbDelta($galleries);

        // ap_images
        $images = "
            CREATE TABLE {$p}ap_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gallery_id BIGINT UNSIGNED NOT NULL,
                storage_key_original VARCHAR(500) NULL,
                storage_key_edited VARCHAR(500) NULL,
                is_selected TINYINT(1) NOT NULL DEFAULT 0,
                client_comments JSON NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY gallery_id (gallery_id),
                KEY sort_order (sort_order)
            ) {$charset};
        ";
        dbDelta($images);

        // ap_magic_links
        $magic_links = "
            CREATE TABLE {$p}ap_magic_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                client_id BIGINT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL,
                purpose VARCHAR(50) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                meta JSON NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token (token)
            ) {$charset};
        ";
        dbDelta($magic_links);

        // ap_download_tokens
        $download_tokens = "
            CREATE TABLE {$p}ap_download_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NOT NULL,
                gallery_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(255) NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                zip_ref VARCHAR(500) NULL,
                require_otp TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY token (token),
                KEY project_id (project_id)
            ) {$charset};
        ";
        dbDelta($download_tokens);

        // ap_logs
        $logs = "
            CREATE TABLE {$p}ap_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                level VARCHAR(20) NOT NULL,
                context VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                trace_id VARCHAR(64) NULL,
                meta JSON NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY context (context),
                KEY trace_id (trace_id),
                KEY created_at (created_at)
            ) {$charset};
        ";
        dbDelta($logs);

        // ap_email_queue
        $email_queue = "
            CREATE TABLE {$p}ap_email_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                to_address VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                headers TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                retries INT NOT NULL DEFAULT 0,
                priority INT NOT NULL DEFAULT 10,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY status (status),
                KEY priority (priority),
                KEY created_at (created_at)
            ) {$charset};
        ";
        dbDelta($email_queue);

        // Create other core tables here with dbDelta as needed...
        self::create_payment_tables();
    }

    private static function migrate_to_109_payments(): void
    {
        global $wpdb;

        $projects = $wpdb->prefix . 'ap_projects';

        // Add columns only if missing (upgrade-safe).
        self::add_column_if_missing($projects, 'payment_provider', "VARCHAR(50) NULL");
        self::add_column_if_missing($projects, 'payment_intent_id', "VARCHAR(100) NULL");
        self::add_column_if_missing($projects, 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($projects, 'payment_amount', "DECIMAL(10,2) NULL");
        self::add_column_if_missing($projects, 'payment_currency', "VARCHAR(10) NULL");
        self::add_column_if_missing($projects, 'payment_last_update', "DATETIME NULL");
        self::add_column_if_missing($projects, 'booking_date', "DATETIME NULL");
        self::add_column_if_missing($projects, 'package_price', "DECIMAL(10,2) NULL");

        // Add index for payment_status if missing.
        self::add_index_if_missing($projects, 'payment_status', 'payment_status');

        // Create audit table.
        self::create_payment_tables();

        // Migrate legacy data (Data Visibility)
        $wpdb->query("
            UPDATE {$projects}
            SET payment_amount = payment_amount_received
            WHERE payment_amount IS NULL
              AND payment_amount_received IS NOT NULL
              AND payment_amount_received != 0
        ");
    }

    private static function create_payment_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $events = "
            CREATE TABLE {$p}ap_payment_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_id BIGINT UNSIGNED NULL,
                event_type VARCHAR(50) NOT NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL,

                PRIMARY KEY  (id),
                KEY project_id (project_id),
                KEY event_type (event_type)
            ) {$charset};
        ";

        /**
         * Note: payload uses LONGTEXT for widest compatibility.
         * JSON column type is not guaranteed across all supported MySQL/MariaDB versions.
         */
        dbDelta($events);
    }

    private static function ensure_payment_foreign_keys(): void
    {
        global $wpdb;

        $projects = $wpdb->prefix . 'ap_projects';
        $events   = $wpdb->prefix . 'ap_payment_events';

        // Table exists check (avoid information_schema failures on restricted hosts).
        $events_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events)) === $events);
        $projects_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $projects)) === $projects);

        if (!$events_exists || !$projects_exists) {
            return;
        }

        // Only attempt FK if both tables are InnoDB.
        $events_engine = (string) $wpdb->get_var($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $events));
        // SHOW TABLE STATUS returns rows; safer engine check:
        $events_status = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $events), ARRAY_A);
        $projects_status = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $projects), ARRAY_A);

        if (!is_array($events_status) || !is_array($projects_status)) {
            return;
        }

        if (($events_status['Engine'] ?? '') !== 'InnoDB' || ($projects_status['Engine'] ?? '') !== 'InnoDB') {
            return;
        }

        // Does FK already exist?
        $fk_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s",
            $events,
            'fk_ap_payment_events_project_id'
        ));

        if ($fk_exists > 0) {
            return;
        }

        // Ensure column types match: BIGINT UNSIGNED NULL in events, BIGINT UNSIGNED NOT NULL in projects.
        // FK with NULL allowed is fine; ON DELETE SET NULL preserves audit history.
        $wpdb->query("SET FOREIGN_KEY_CHECKS=0");

        // Some hosts disallow ALTER TABLE; fail-soft.
        $result = $wpdb->query("
            ALTER TABLE {$events}
            ADD CONSTRAINT fk_ap_payment_events_project_id
            FOREIGN KEY (project_id)
            REFERENCES {$projects}(id)
            ON DELETE SET NULL
        ");

        $wpdb->query("SET FOREIGN_KEY_CHECKS=1");

        // If it fails, we intentionally do nothing further: schema still functions without FK.
        unset($result);
    }

    private static function add_column_if_missing(string $table, string $column, string $definition): void
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s",
            $table,
            $column
        ));

        if ($exists > 0) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function add_index_if_missing(string $table, string $index_name, string $column): void
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND INDEX_NAME = %s",
            $table,
            $index_name
        ));

        if ($exists > 0) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD INDEX {$index_name} ({$column})");
    }
}

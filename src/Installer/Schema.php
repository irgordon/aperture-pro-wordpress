<?php

namespace AperturePro\Installer;

class Schema {

    public static function createTables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $tables = [];

        /**
         * ---------------------------------------------------------------------
         * ap_projects
         * ---------------------------------------------------------------------
         * Stores the lifecycle of a client project.
         * Used by:
         *  - Workflow.php (status transitions)
         *  - Admin Command Center (project summary)
         *  - Client Portal session state
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'lead',
    session_date DATETIME NULL,
    eta_delivery DATETIME NULL,
    booking_date DATETIME NULL,
    package_price DECIMAL(10,2) NULL,
    payment_status VARCHAR(50) NULL DEFAULT 'pending',
    payment_provider VARCHAR(50) NULL,
    payment_intent_id VARCHAR(255) NULL,
    payment_amount_received DECIMAL(10,2) NULL DEFAULT 0.00,
    payment_currency VARCHAR(3) NULL,
    payment_last_update DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY client_id (client_id),
    KEY status (status)
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_payment_events
         * ---------------------------------------------------------------------
         * Audit log for all payment-related webhooks and manual actions.
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY project_id (project_id)
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_clients
         * ---------------------------------------------------------------------
         * Clients are NOT WordPress users.
         * Used by:
         *  - MagicLinkService (email validation)
         *  - AdminController (project creation)
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    validated_email_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY email (email)
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_galleries
         * ---------------------------------------------------------------------
         * Each project has:
         *  - Proof gallery (type = proof)
         *  - Final gallery (type = final)
         * Used by:
         *  - ClientProofController
         *  - Workflow.php
         *  - Download system
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_galleries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'uploaded',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY project_id (project_id)
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_images
         * ---------------------------------------------------------------------
         * Stores proof and final images.
         * Updated frequently by:
         *  - ClientProofController (select, comment)
         *  - Admin upload handlers
         * JSON comments support your React proofing UI.
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_images (
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
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_magic_links
         * ---------------------------------------------------------------------
         * Passwordless login tokens.
         * Used by:
         *  - AuthController::consume_magic_link
         *  - MagicLinkService
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_magic_links (
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
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_download_tokens
         * ---------------------------------------------------------------------
         * One-time ZIP download tokens.
         * Used by:
         *  - DownloadController
         *  - ZipStreamService
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_download_tokens (
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
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * ---------------------------------------------------------------------
         * ap_logs
         * ---------------------------------------------------------------------
         * Centralized logging for:
         *  - REST error boundaries
         *  - Workflow transitions
         *  - Upload failures
         *  - Download failures
         * Used by:
         *  - Logger.php
         *  - AdminController::get_logs
         *  - Admin Command Center UI
         */
        $tables[] = <<<SQL
CREATE TABLE {$prefix}ap_logs (
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
) ENGINE=InnoDB {$charset};
SQL;

        /**
         * Execute all table creation statements
         */
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }
}

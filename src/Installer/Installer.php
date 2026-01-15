<?php

namespace AperturePro\Installer;

use AperturePro\Config\Config;
use AperturePro\Config\Defaults;
use AperturePro\Config\Validator;
use AperturePro\Helpers\Logger;

class Installer
{
    /**
     * Runs on plugin activation.
     * Called by Activator::activate().
     *
     * Responsibilities:
     *  - Create database tables
     *  - Initialize configuration (first run)
     *  - Run migrations if needed
     *  - Fail softly and log errors
     */
    public static function runInitialSetup(): void
    {
        try {
            // 1. Create all custom tables
            Schema::createTables();

            // 2. Initialize configuration on first run
            self::initializeConfig();

            // 3. Run migrations if needed
            self::runMigrations();

        } catch (\Throwable $e) {
            // Fail-soft: never break activation
            Logger::log(
                'error',
                'installer',
                'Installer failed: ' . $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    /**
     * Initializes plugin configuration on first run.
     *
     * Uses:
     *  - Config::OPTION_KEY
     *  - Defaults::all()
     *  - Validator::validate()
     *
     * Creates:
     *  - aperture_pro_config (array)
     *  - aperture_pro_installed (timestamp)
     */
    protected static function initializeConfig(): void
    {
        $optionKey = Config::OPTION_KEY;

        // If config already exists, do nothing
        $existing = get_option($optionKey);
        if (!empty($existing) && is_array($existing)) {
            return;
        }

        // Load default configuration
        $defaults = Defaults::all();

        // Validate defaults (future-proof)
        $validated = Validator::validate($defaults);

        // Save configuration
        update_option($optionKey, $validated, false);

        // Mark installation timestamp
        if (!get_option('aperture_pro_installed')) {
            update_option('aperture_pro_installed', current_time('mysql'), false);
        }

        Logger::log(
            'info',
            'installer',
            'Aperture Pro configuration initialized.',
            ['config' => $validated]
        );
    }

    /**
     * Handles versioned migrations.
     *
     * Uses:
     *  - aperture_pro_version option
     *  - Incremental upgrade routines
     *
     * This ensures future updates can modify schema or config safely.
     */
    protected static function runMigrations(): void
    {
        $currentVersion = get_option('aperture_pro_version');
        $pluginVersion  = self::getPluginVersion();

        // First install: set version
        if (!$currentVersion) {
            update_option('aperture_pro_version', $pluginVersion, false);
            return;
        }

        // No migration needed
        if (version_compare($currentVersion, $pluginVersion, '>=')) {
            return;
        }

        // Example migration structure (future-proof)
        if (version_compare($currentVersion, '1.1.0', '<')) {
            self::migrateTo110();
        }

        if (version_compare($currentVersion, '1.2.0', '<')) {
            self::migrateTo120();
        }

        // Update stored version
        update_option('aperture_pro_version', $pluginVersion, false);

        Logger::log(
            'info',
            'installer',
            "Aperture Pro upgraded from {$currentVersion} to {$pluginVersion}."
        );
    }

    /**
     * Example migration routine for version 1.1.0
     */
    protected static function migrateTo110(): void
    {
        try {
            // Placeholder for future schema or config changes
            // Example:
            // Schema::addNewColumn();
            // Config::set('new_setting', true);

            Logger::log('info', 'migration', 'Migration to 1.1.0 completed.');
        } catch (\Throwable $e) {
            Logger::log(
                'error',
                'migration',
                'Migration to 1.1.0 failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Example migration routine for version 1.2.0
     */
    protected static function migrateTo120(): void
    {
        try {
            // Placeholder for future schema or config changes

            Logger::log('info', 'migration', 'Migration to 1.2.0 completed.');
        } catch (\Throwable $e) {
            Logger::log(
                'error',
                'migration',
                'Migration to 1.2.0 failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Retrieves plugin version from plugin header.
     */
    protected static function getPluginVersion(): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = dirname(__DIR__, 2) . '/aperture-pro.php';
        $data       = get_plugin_data($pluginFile);

        return $data['Version'] ?? '1.0.0';
    }
}

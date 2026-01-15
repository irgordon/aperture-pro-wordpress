<?php

namespace AperturePro\Installer;

use AperturePro\Helpers\Logger;

class Activator
{
    /**
     * Called by register_activation_hook().
     *
     * Responsibilities:
     *  - Run installer setup
     *  - Store plugin version
     *  - Fail softly and log errors
     */
    public static function activate(): void
    {
        try {
            // Run full installer lifecycle
            Installer::runInitialSetup();

            // Store plugin version for future migrations
            $version = self::getPluginVersion();
            update_option('aperture_pro_version', $version, false);

            Logger::log(
                'info',
                'activation',
                "Aperture Pro activated. Version: {$version}"
            );

        } catch (\Throwable $e) {
            // Fail-soft: never break activation
            Logger::log(
                'error',
                'activation',
                'Activation failed: ' . $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    /**
     * Called by register_deactivation_hook().
     *
     * Currently no deactivation logic is required,
     * but this method exists for future expansion.
     */
    public static function deactivate(): void
    {
        try {
            Logger::log(
                'info',
                'deactivation',
                'Aperture Pro deactivated.'
            );
        } catch (\Throwable $e) {
            Logger::log(
                'error',
                'deactivation',
                'Deactivation error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Called by register_uninstall_hook().
     *
     * Delegates to uninstall.php for:
     *  - JSON export of configuration
     *  - Safe cleanup of plugin options
     *  - No destructive deletion of media
     */
    public static function uninstall(): void
    {
        try {
            $uninstallFile = dirname(__DIR__, 2) . '/uninstall.php';

            if (file_exists($uninstallFile)) {
                require_once $uninstallFile;
            }

            Logger::log(
                'info',
                'uninstall',
                'Aperture Pro uninstalled.'
            );

        } catch (\Throwable $e) {
            Logger::log(
                'error',
                'uninstall',
                'Uninstall failed: ' . $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    /**
     * Retrieves plugin version from plugin header.
     *
     * Used by:
     *  - activate() to store version
     *  - Installer::runMigrations() to compare versions
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

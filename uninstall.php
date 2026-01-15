<?php
/**
 * Aperture Pro Uninstall Script
 *
 * Responsibilities:
 *  - Export configuration to JSON
 *  - Safely remove plugin options
 *  - Never delete media or custom tables by default
 *  - Fail softly and log errors
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

use AperturePro\Config\Config;
use AperturePro\Helpers\Logger;

// Ensure autoloading is available
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

try {
    /**
     * ------------------------------------------------------------------
     * Export configuration to JSON
     * ------------------------------------------------------------------
     */
    $config = Config::all();

    if (!empty($config)) {
        $export = [
            'exported_at' => current_time('mysql'),
            'plugin'      => 'Aperture Pro',
            'version'     => get_option('aperture_pro_version'),
            'config'      => $config,
        ];

        $uploadDir = wp_upload_dir();
        $exportDir = trailingslashit($uploadDir['basedir']) . 'aperture-pro-backups';

        if (!file_exists($exportDir)) {
            wp_mkdir_p($exportDir);
        }

        $filename = 'aperture-pro-config-' . date('Y-m-d-His') . '.json';
        $path     = trailingslashit($exportDir) . $filename;

        file_put_contents($path, wp_json_encode($export, JSON_PRETTY_PRINT));

        Logger::log(
            'info',
            'uninstall',
            'Configuration exported during uninstall.',
            ['path' => $path]
        );
    }

    /**
     * ------------------------------------------------------------------
     * Remove plugin options
     * ------------------------------------------------------------------
     */
    delete_option(Config::OPTION_KEY);
    delete_option('aperture_pro_installed');
    delete_option('aperture_pro_version');

    Logger::log(
        'info',
        'uninstall',
        'Plugin options removed successfully.'
    );

} catch (\Throwable $e) {
    // Fail-soft: uninstall must never fatal
    if (class_exists(Logger::class)) {
        Logger::log(
            'error',
            'uninstall',
            'Uninstall failed: ' . $e->getMessage(),
            ['trace' => $e->getTraceAsString()]
        );
    }
}

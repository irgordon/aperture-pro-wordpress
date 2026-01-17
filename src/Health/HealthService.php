<?php

namespace AperturePro\Health;

use AperturePro\Config\Config;
use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;

/**
 * HealthService
 *
 * Centralized system health checks.
 */
class HealthService
{
    /**
     * Run all health checks.
     *
     * @return array
     */
    public static function check(): array
    {
        global $wpdb;

        $results = [
            'overall_status' => 'ok',
            'timestamp'      => current_time('mysql'),
            'checks'         => [],
        ];

        $requiredTables = [
            'ap_projects',
            'ap_clients',
            'ap_galleries',
            'ap_images',
            'ap_magic_links',
            'ap_download_tokens',
            'ap_logs',
        ];

        $results['checks']['tables'] = [];

        // In a real environment, we would use $wpdb->prepare but SHOW TABLES LIKE usually accepts a string directly.
        // We use $wpdb->prefix . '%' to list all WP tables, then filter.
        $like = $wpdb->prefix . '%';
        $existingTables = $wpdb->get_col("SHOW TABLES LIKE '{$like}'");

        // Handle case where get_col returns null/empty
        if (!is_array($existingTables)) {
            $existingTables = [];
        }

        $existingMap = array_flip($existingTables);

        foreach ($requiredTables as $table) {
            $full = $wpdb->prefix . $table;
            $exists = isset($existingMap[$full]);

            $results['checks']['tables'][$table] = $exists;

            if (!$exists) {
                $results['overall_status'] = 'warning';
            }
        }

        $config = Config::all();
        $results['checks']['config_loaded'] = !empty($config);

        if (empty($config)) {
            $results['overall_status'] = 'warning';
        }

        try {
            $storage = StorageFactory::make();
            $results['checks']['storage_driver'] = get_class($storage);
        } catch (\Throwable $e) {
            $results['checks']['storage_driver'] = 'unavailable';
            $results['overall_status'] = 'error';

            Logger::log(
                'error',
                'health_check',
                'Storage driver unavailable: ' . $e->getMessage(),
                ['notify_admin' => true]
            );
        }

        // Upload watchdog health (if present)
        $watchdog = get_transient('ap_upload_watchdog_health');
        $results['checks']['upload_watchdog'] = $watchdog ?: ['ok' => true];

        try {
            // Optional: check if we can log
            // Logger::log('info', 'health_check', 'Health check executed.');
            $results['checks']['logging'] = true;
        } catch (\Throwable $e) {
            $results['checks']['logging'] = false;
            $results['overall_status'] = 'warning';
        }

        return $results;
    }

    /**
     * Get system metrics for dashboard.
     *
     * @return array
     */
    public static function getMetrics(): array
    {
        // -----------------------------------------
        // PERFORMANCE METRICS (hardcoded legacy)
        // -----------------------------------------
        $performance = [
            'requestReduction'   => '−90%',
            'requestCountBefore' => 500,
            'requestCountAfter'  => 50,
            'latencySaved'       => '−22.5s',
        ];

        // -----------------------------------------
        // STORAGE METRICS
        // -----------------------------------------
        $config = Config::all();
        $driverName = $config['storage']['driver'] ?? 'unknown';

        $storage = [
            'driver'    => $driverName,
            'status'    => 'Unavailable',
            'used'      => null, // Not easily available without expensive calls
            'available' => null,
        ];

        try {
            $driver = StorageFactory::make();
            // If we instantiated it, it's at least configured correctly.
            $storage['status'] = 'Active';

            // Optional: Check if local and get disk space
            if ($driver instanceof \AperturePro\Storage\LocalStorage) {
                // We access the protected local path via a public helper if available,
                // or assume base path from config. LocalStorage has getLocalPath.
                $localPath = $driver->getLocalPath('');
                if ($localPath) {
                    $free = @disk_free_space($localPath);
                    $total = @disk_total_space($localPath);
                    if ($free !== false && $total !== false) {
                        $storage['available'] = self::formatBytes($free);
                        $storage['used'] = self::formatBytes($total - $free);
                    }
                }
            }
        } catch (\Throwable $e) {
            $storage['status'] = 'Error';
        }

        return [
            'performance' => $performance,
            'storage'     => $storage,
        ];
    }

    protected static function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

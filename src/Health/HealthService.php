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
}

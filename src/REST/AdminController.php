<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Config\Config;
use AperturePro\Storage\StorageFactory;
use AperturePro\Helpers\Logger;

class AdminController extends BaseController {

    public function register_routes(): void {
        register_rest_route($this->namespace, '/admin/health-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_check'],
            'permission_callback' => [$this, 'require_admin'],
        ]);
    }

    public function require_admin(): bool {
        return current_user_can('manage_options');
    }

    public function health_check(WP_REST_Request $request) {
        return $this->with_error_boundary(function () {
            global $wpdb;

            $results = [
                'overall_status' => 'ok',
                'timestamp'      => current_time('mysql'),
                'checks'         => [],
            ];

            /**
             * ------------------------------------------------------------
             * Database Tables
             * ------------------------------------------------------------
             */
            $requiredTables = [
                'ap_projects',
                'ap_clients',
                'ap_galleries',
                'ap_images',
                'ap_magic_links',
                'ap_download_tokens',
                'ap_logs',
            ];

            foreach ($requiredTables as $table) {
                $full = $wpdb->prefix . $table;
                $exists = ($wpdb->get_var("SHOW TABLES LIKE '{$full}'") === $full);

                $results['checks']['tables'][$table] = $exists;

                if (!$exists) {
                    $results['overall_status'] = 'warning';
                }
            }

            /**
             * ------------------------------------------------------------
             * Configuration
             * ------------------------------------------------------------
             */
            $config = Config::all();
            $results['checks']['config_loaded'] = !empty($config);

            if (empty($config)) {
                $results['overall_status'] = 'warning';
            }

            /**
             * ------------------------------------------------------------
             * Storage Driver
             * ------------------------------------------------------------
             */
            try {
                $storage = StorageFactory::make();
                $results['checks']['storage_driver'] = get_class($storage);
            } catch (\Throwable $e) {
                $results['checks']['storage_driver'] = 'unavailable';
                $results['overall_status'] = 'error';

                Logger::log(
                    'error',
                    'health_check',
                    'Storage driver unavailable: ' . $e->getMessage()
                );
            }

            /**
             * ------------------------------------------------------------
             * Logging
             * ------------------------------------------------------------
             */
            try {
                Logger::log('info', 'health_check', 'Health check executed.');
                $results['checks']['logging'] = true;
            } catch (\Throwable $e) {
                $results['checks']['logging'] = false;
                $results['overall_status'] = 'warning';
            }

            return $this->respond_success($results);
        }, ['endpoint' => 'admin_health_check']);
    }
}

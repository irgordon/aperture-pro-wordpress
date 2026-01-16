<?php

namespace AperturePro\Config;

use AperturePro\Admin\AdminUI;

class Config {
    // Use the same option key as AdminUI
    const OPTION_KEY = 'aperture_pro_settings';

    public static function get($key, $default = null) {
        $config = self::all();
        // Support simple key access or return default
        return $config[$key] ?? $default;
    }

    public static function set($key, $value) {
        // Note: Writing back to this abstraction is discouraged due to structure mismatch.
        // Direct modification of the WP option is preferred for admin settings.
        $settings = get_option(self::OPTION_KEY, []);
        $settings[$key] = $value;
        update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Return all configuration, mapping flat AdminUI settings to the nested structure
     * expected by services like StorageFactory.
     */
    public static function all() {
        $settings = get_option(self::OPTION_KEY, []);

        return [
            'storage' => [
                'driver' => $settings['storage_driver'] ?? 'local',
                'local_path' => $settings['local_storage_path'] ?? '',

                's3' => [
                    'bucket' => $settings['s3_bucket'] ?? '',
                    'region' => $settings['s3_region'] ?? '',
                    'access_key' => $settings['s3_access_key'] ?? '', // Encrypted
                    'secret_key' => $settings['s3_secret_key'] ?? '', // Encrypted
                    'cloudfront_domain' => $settings['cloudfront_domain'] ?? '',
                    'cloudfront_key_pair_id' => $settings['cloudfront_key_pair_id'] ?? '',
                    'cloudfront_private_key' => $settings['cloudfront_private_key'] ?? '', // Encrypted
                ],

                'cloudinary' => [
                    // Note: AdminUI only provides cloud_api_key.
                    // Full Cloudinary support requires UI updates for cloud_name and api_secret.
                    'api_key' => $settings['cloud_api_key'] ?? '',
                ],

                'imagekit' => [
                     'private_key' => $settings['cloud_api_key'] ?? '',
                ]
            ],

            'email' => [
                'sender' => $settings['email_sender'] ?? '',
            ],

            'security' => [
                'require_otp' => !empty($settings['require_otp']),
                'webhook_secret' => $settings['webhook_secret'] ?? '',
            ],

            'theme_overrides' => !empty($settings['theme_overrides']),
        ];
    }
}

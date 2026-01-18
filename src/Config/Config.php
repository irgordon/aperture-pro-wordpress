<?php

namespace AperturePro\Config;

use AperturePro\Admin\AdminUI;

class Config
{
    /**
     * Use the same option key as AdminUI.
     * AdminUI remains the source of truth for structure and validation.
     */
    const OPTION_KEY = 'aperture_pro_settings';

    /**
     * Retrieve configuration value.
     *
     * Supports dot-notation access:
     *   Config::get('storage.driver')
     *   Config::get('paypal.mode')
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $config = self::all();

        // Simple key access
        if (strpos($key, '.') === false) {
            return $config[$key] ?? $default;
        }

        // Dot-notation traversal
        $segments = explode('.', $key);
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Write a single flat configuration value.
     *
     * ⚠️ Writing nested arrays through this method is intentionally blocked.
     * AdminUI owns structure and validation. This method exists only for
     * narrowly-scoped runtime overrides or migrations.
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set(string $key, $value): void
    {
        if (is_array($value)) {
            throw new \LogicException(
                'Config::set does not support nested values. ' .
                'Modify settings via AdminUI or update the option directly.'
            );
        }

        $settings = get_option(self::OPTION_KEY, []);
        $settings[$key] = $value;

        update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Return all configuration, mapping flat AdminUI settings
     * into the nested structure expected by services.
     *
     * NOTE:
     * - Encrypted values are returned as-is.
     * - Decryption is the responsibility of consuming services
     *   (e.g. StorageFactory, PaymentService).
     *
     * @return array
     */
    public static function all(): array
    {
        $settings = get_option(self::OPTION_KEY, []);

        return [
            'storage' => [
                'driver'     => $settings['storage_driver'] ?? 'local',
                'local_path' => $settings['local_storage_path'] ?? '',

                's3' => [
                    'bucket'                  => $settings['s3_bucket'] ?? '',
                    'region'                  => $settings['s3_region'] ?? '',
                    'access_key'              => $settings['s3_access_key'] ?? '', // Encrypted
                    'secret_key'              => $settings['s3_secret_key'] ?? '', // Encrypted
                    'cloudfront_domain'       => $settings['cloudfront_domain'] ?? '',
                    'cloudfront_key_pair_id'  => $settings['cloudfront_key_pair_id'] ?? '',
                    'cloudfront_private_key'  => $settings['cloudfront_private_key'] ?? '', // Encrypted
                ],

                'cloudinary' => [
                    // TODO: AdminUI currently only exposes cloud_api_key.
                    // Full Cloudinary support requires cloud_name and api_secret fields.
                    'api_key' => $settings['cloud_api_key'] ?? '',
                ],

                'imagekit' => [
                    // TODO: ImageKit currently reuses cloud_api_key.
                    // AdminUI must be expanded before supporting Cloudinary + ImageKit simultaneously.
                    'private_key' => $settings['cloud_api_key'] ?? '',
                ],
            ],

            'email' => [
                'sender' => $settings['email_sender'] ?? '',
            ],

            'stripe' => [
                'secret_key'     => $settings['stripe_secret_key'] ?? '',
                'webhook_secret' => $settings['stripe_webhook_secret'] ?? '',
            ],

            'paypal' => [
                'client_id'  => $settings['paypal_client_id'] ?? '',
                'secret'     => $settings['paypal_secret'] ?? '',
                'webhook_id' => $settings['paypal_webhook_id'] ?? '',
                'mode'       => $settings['paypal_mode'] ?? 'sandbox',
            ],

            'security' => [
                'require_otp'    => !empty($settings['require_otp']),
                'webhook_secret' => $settings['webhook_secret'] ?? '',
            ],

            'theme_overrides' => !empty($settings['theme_overrides']),
        ];
    }
}

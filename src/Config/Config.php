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
     * Cache for the parsed configuration.
     * @var array|null
     */
    private static $cache = null;

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

        // Invalidate cache so next call re-fetches
        self::$cache = null;
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
        if (self::$cache !== null) {
            return self::$cache;
        }

        $settings = get_option(self::OPTION_KEY, []);

        self::$cache = [
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
                    'cloud_name' => $settings['cloudinary_cloud_name'] ?? '',
                    'api_key'    => $settings['cloud_api_key'] ?? '',
                    'api_secret' => $settings['cloudinary_api_secret'] ?? '',
                ],

                'imagekit' => [
                    'public_key'   => $settings['imagekit_public_key'] ?? '',
                    'private_key'  => $settings['imagekit_private_key'] ?? '',
                    'url_endpoint' => $settings['imagekit_url_endpoint'] ?? '',
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
                'expose_rate_limit_headers' => !empty($settings['expose_rate_limit_headers']),
            ],

            'proofing' => [
                'placeholder_url' => $settings['custom_placeholder_url'] ?? '',
                'allow_original_fallback' => !empty($settings['proof_allow_original_fallback']),
            ],

            'theme_overrides' => !empty($settings['theme_overrides']),

            'upload' => [
                'auto_cleanup_remote_on_failure' => $settings['upload_auto_cleanup_failure'] ?? true,
            ],
        ];

        return self::$cache;
    }
}

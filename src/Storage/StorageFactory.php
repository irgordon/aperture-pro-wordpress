<?php

namespace AperturePro\Storage;

use AperturePro\Config\Config;
use AperturePro\Helpers\Crypto;
use AperturePro\Helpers\Logger;

/**
 * StorageFactory
 *
 * Creates storage driver instances based on configuration.
 */
class StorageFactory
{
    /**
     * Cache for storage driver instances.
     * @var array<string, StorageInterface>
     */
    private static $instances = [];

    /**
     * Reset the storage factory cache.
     * Useful for testing to prevent state pollution.
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    /**
     * Create a storage driver instance.
     *
     * @param string|null $driver
     * @return StorageInterface
     */
    public static function make(?string $driver = null): StorageInterface
    {
        return self::create($driver);
    }

    /**
     * Create a storage driver instance.
     *
     * @param string|null $driver
     * @return StorageInterface
     */
    public static function create(?string $driver = null): StorageInterface
    {
        // Optimization: Check cache immediately if driver name is known
        if ($driver && isset(self::$instances[$driver])) {
            return self::$instances[$driver];
        }

        $config = Config::all();
        $resolvedDriver = $driver ?: ($config['storage']['driver'] ?? 'local');

        // Check cache again with resolved driver name
        if (isset(self::$instances[$resolvedDriver])) {
            return self::$instances[$resolvedDriver];
        }

        switch ($resolvedDriver) {
            case 'cloudinary':
                self::$instances[$resolvedDriver] = self::createCloudinary($config);
                break;

            case 'imagekit':
                self::$instances[$resolvedDriver] = self::createImageKit($config);
                break;

            case 's3':
                self::$instances[$resolvedDriver] = self::createS3($config);
                break;

            case 'local':
            default:
                self::$instances[$resolvedDriver] = self::createLocal($config);
                break;
        }

        return self::$instances[$resolvedDriver];
    }

    protected static function createLocal(array $config): StorageInterface
    {
        $uploadDir = wp_upload_dir();
        $basePath = $uploadDir['basedir'] . '/aperture';
        $baseUrl  = $uploadDir['baseurl'] . '/aperture';

        if (!empty($config['storage']['local_path'])) {
            $basePath = rtrim($uploadDir['basedir'], '/') . '/' . ltrim($config['storage']['local_path'], '/');
            $baseUrl  = rtrim($uploadDir['baseurl'], '/') . '/' . ltrim($config['storage']['local_path'], '/');
        }

        return new LocalStorage([
            'base_path' => $basePath,
            'base_url'  => $baseUrl,
        ]);
    }

    protected static function createCloudinary(array $config): StorageInterface
    {
        $cloudName = $config['storage']['cloudinary']['cloud_name'] ?? '';
        $apiKeyEnc = $config['storage']['cloudinary']['api_key'] ?? '';
        $apiSecretEnc = $config['storage']['cloudinary']['api_secret'] ?? '';

        $apiKey = $apiKeyEnc ? Crypto::decrypt($apiKeyEnc) : null;
        $apiSecret = $apiSecretEnc ? Crypto::decrypt($apiSecretEnc) : null;

        return new CloudinaryStorage([
            'cloud_name' => $cloudName,
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret,
        ]);
    }

    protected static function createImageKit(array $config): StorageInterface
    {
        $publicKey     = $config['storage']['imagekit']['public_key'] ?? '';
        $privateKeyEnc = $config['storage']['imagekit']['private_key'] ?? '';
        $urlEndpoint   = $config['storage']['imagekit']['url_endpoint'] ?? '';

        $privateKey = $privateKeyEnc ? Crypto::decrypt($privateKeyEnc) : null;

        return new ImageKitStorage([
            'public_key'   => $publicKey,
            'private_key'  => $privateKey,
            'url_endpoint' => $urlEndpoint,
        ]);
    }

    protected static function createS3(array $config): StorageInterface
    {
        $s3Config = $config['storage']['s3'] ?? [];

        // Decrypt credentials if stored encrypted
        $accessKeyEnc = $s3Config['access_key'] ?? '';
        $secretKeyEnc = $s3Config['secret_key'] ?? '';

        $accessKey = $accessKeyEnc ? Crypto::decrypt($accessKeyEnc) : null;
        $secretKey = $secretKeyEnc ? Crypto::decrypt($secretKeyEnc) : null;

        if (!$accessKey || !$secretKey) {
            Logger::log('error', 'storage', 'S3 credentials missing or failed to decrypt', ['notify_admin' => true]);
        }

        $bucket = $s3Config['bucket'] ?? '';
        $region = $s3Config['region'] ?? '';

        $cloudfrontDomain     = $s3Config['cloudfront_domain'] ?? null;
        $cfKeyPairId          = $s3Config['cloudfront_key_pair_id'] ?? null;
        $cfPrivateKeyEnc      = $s3Config['cloudfront_private_key'] ?? null;
        $cfPrivateKey         = $cfPrivateKeyEnc ? Crypto::decrypt($cfPrivateKeyEnc) : null;
        $defaultAcl           = $s3Config['default_acl'] ?? 'private';

        return new S3Storage([
            'bucket'                 => $bucket,
            'region'                 => $region,
            'access_key'             => $accessKey,
            'secret_key'             => $secretKey,
            'cloudfront_domain'      => $cloudfrontDomain,
            'cloudfront_key_pair_id' => $cfKeyPairId,
            'cloudfront_private_key' => $cfPrivateKey,
            'default_acl'            => $defaultAcl,
        ]);
    }
}

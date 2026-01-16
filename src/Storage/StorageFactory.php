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
        $config = Config::all();
        $driver = $driver ?: ($config['storage']['driver'] ?? 'local');

        switch ($driver) {
            case 'cloudinary':
                return self::createCloudinary($config);

            case 'imagekit':
                return self::createImageKit($config);

            case 's3':
                return self::createS3($config);

            case 'local':
            default:
                return self::createLocal($config);
        }
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
        $publicKeyEnc  = $config['storage']['imagekit']['public_key'] ?? '';
        $privateKeyEnc = $config['storage']['imagekit']['private_key'] ?? '';
        $urlEndpoint   = $config['storage']['imagekit']['url_endpoint'] ?? '';

        $publicKey  = $publicKeyEnc ? Crypto::decrypt($publicKeyEnc) : null;
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

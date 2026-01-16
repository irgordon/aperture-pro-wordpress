<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;

/**
 * CloudinaryStorage
 *
 * Minimal Cloudinary driver. This implementation expects the Cloudinary PHP SDK
 * to be available (cloudinary/cloudinary_php). If the SDK is not installed, the driver
 * will throw an exception on construction.
 *
 * NOTE: This implementation focuses on common operations. For production you
 * should add robust error handling, retries, and support for large uploads.
 */
class CloudinaryStorage implements StorageInterface
{
    private const MAX_RETRIES = 3;
    private const CHUNK_SIZE = 6 * 1024 * 1024; // 6MB
    private const LARGE_FILE_THRESHOLD = 20 * 1024 * 1024; // 20MB

    protected array $config;
    protected $client;

    public function __construct(array $config = [])
    {
        $this->config = $config;

        if (!class_exists('\Cloudinary\Cloudinary')) {
            Logger::log('error', 'cloudinary', 'Cloudinary SDK not found');
            throw new \RuntimeException('Cloudinary SDK not installed. Please require cloudinary/cloudinary_php via Composer.');
        }

        $cloudName = $config['cloud_name'] ?? '';
        $apiKey    = $config['api_key'] ?? '';
        $apiSecret = $config['api_secret'] ?? '';

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            Logger::log('error', 'cloudinary', 'Cloudinary configuration incomplete', $config);
            throw new \RuntimeException('Cloudinary configuration incomplete.');
        }

        // Cloudinary PHP SDK v2+ uses Cloudinary\Cloudinary
        $this->client = new \Cloudinary\Cloudinary([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => [
                'secure' => true,
            ],
        ]);
    }

    public function upload(string $localPath, string $remoteKey, array $options = []): array
    {
        try {
            $publicId = pathinfo($remoteKey, PATHINFO_FILENAME);
            $folder = dirname($remoteKey);
            if ($folder === '.' || $folder === '/') {
                $folder = '';
            }

            $uploadOptions = [
                'public_id' => $publicId,
            ];

            if (!empty($folder)) {
                $uploadOptions['folder'] = $folder;
            }

            if (!empty($options['resource_type'])) {
                $uploadOptions['resource_type'] = $options['resource_type'];
            }

            // Handle large uploads
            if (file_exists($localPath) && filesize($localPath) > self::LARGE_FILE_THRESHOLD) {
                $uploadOptions['chunk_size'] = self::CHUNK_SIZE;
            }

            $response = $this->executeWithRetry(function () use ($localPath, $uploadOptions) {
                return $this->client->uploadApi()->upload($localPath, $uploadOptions);
            }, 'upload', ['remoteKey' => $remoteKey]);

            $url = $response['secure_url'] ?? ($response['url'] ?? null);
            $key = $response['public_id'] ?? $remoteKey;

            return [
                'success' => true,
                'key'     => (string) $key,
                'url'     => $url,
                'meta'    => $response,
            ];
        } catch (\Throwable $e) {
            Logger::log('error', 'cloudinary', 'Upload failed: ' . $e->getMessage(), ['remoteKey' => $remoteKey]);
            return ['success' => false, 'key' => $remoteKey, 'url' => null, 'meta' => []];
        }
    }

    public function getUrl(string $remoteKey, array $options = []): ?string
    {
        try {
            $publicId = $remoteKey;
            $params = [];

            if (!empty($options['signed']) && !empty($this->config['api_secret'])) {
                // Cloudinary supports signed URLs via the SDK; use the URL builder if available.
                if (method_exists($this->client, 'image')) {
                    $image = $this->client->image($publicId);
                    if (!empty($options['transformation'])) {
                        $image->addTransformation($options['transformation']);
                    }
                    if (!empty($options['expires'])) {
                        // Cloudinary signed URLs require additional logic; skipping for now.
                    }
                    return (string) $image->toUrl();
                }
            }

            // Fallback: construct URL from cloud name and public id
            $cloudName = $this->config['cloud_name'] ?? '';
            if (empty($cloudName)) {
                return null;
            }

            $resourceType = $options['resource_type'] ?? 'image';
            $format = $options['format'] ?? 'jpg';

            return "https://res.cloudinary.com/{$cloudName}/{$resourceType}/upload/{$publicId}.{$format}";
        } catch (\Throwable $e) {
            Logger::log('warning', 'cloudinary', 'getUrl failed: ' . $e->getMessage(), ['remoteKey' => $remoteKey]);
            return null;
        }
    }

    public function delete(string $remoteKey): bool
    {
        try {
            $response = $this->executeWithRetry(function () use ($remoteKey) {
                return $this->client->uploadApi()->destroy($remoteKey);
            }, 'delete', ['remoteKey' => $remoteKey]);

            return isset($response['result']) && in_array($response['result'], ['ok', 'deleted'], true);
        } catch (\Throwable $e) {
            Logger::log('warning', 'cloudinary', 'Delete failed: ' . $e->getMessage(), ['remoteKey' => $remoteKey]);
            return false;
        }
    }

    public function exists(string $remoteKey): bool
    {
        try {
            $response = $this->executeWithRetry(function () use ($remoteKey) {
                return $this->client->adminApi()->asset($remoteKey);
            }, 'exists', ['remoteKey' => $remoteKey]);

            return !empty($response);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function list(string $prefix = '', array $options = []): array
    {
        try {
            $params = [
                'type' => $options['type'] ?? 'upload',
                'prefix' => $prefix,
                'max_results' => $options['max_results'] ?? 100,
            ];

            $response = $this->executeWithRetry(function () use ($params) {
                return $this->client->adminApi()->assets($params);
            }, 'list', ['prefix' => $prefix]);

            $results = [];

            if (!empty($response['resources'])) {
                foreach ($response['resources'] as $res) {
                    $results[] = [
                        'key' => $res['public_id'],
                        'url' => $res['secure_url'] ?? ($res['url'] ?? null),
                        'size'=> $res['bytes'] ?? null,
                        'format' => $res['format'] ?? null,
                        'created_at' => $res['created_at'] ?? null,
                    ];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Logger::log('warning', 'cloudinary', 'List failed: ' . $e->getMessage(), ['prefix' => $prefix]);
            return [];
        }
    }

    /**
     * Execute a callable with retry logic.
     *
     * @param callable $callback The operation to execute.
     * @param string   $action   Action name for logging.
     * @param array    $context  Context for logging.
     * @return mixed
     * @throws \Throwable
     */
    private function executeWithRetry(callable $callback, string $action, array $context = [])
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRIES) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempts++;

                // If max retries reached, don't sleep, just exit loop to throw
                if ($attempts >= self::MAX_RETRIES) {
                    break;
                }

                Logger::log('warning', 'cloudinary', "Retry {$attempts}/" . self::MAX_RETRIES . " for {$action}: " . $e->getMessage(), $context);

                // Exponential backoff: 1s, 2s, 4s...
                sleep(pow(2, $attempts - 1));
            }
        }

        throw $lastException;
    }
}

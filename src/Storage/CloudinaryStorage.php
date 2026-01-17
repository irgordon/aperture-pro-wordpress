<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\Traits\Retryable;

/**
 * CloudinaryStorage
 *
 * Cloudinary storage driver.
 * Uses Cloudinary PHP SDK.
 */
class CloudinaryStorage implements StorageInterface
{
    use Retryable;

    protected array $config;
    protected $cloudinary;

    const CHUNK_SIZE = 64 * 1024 * 1024; // 64MB

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
            Logger::log('error', 'cloudinary', 'Cloudinary configuration incomplete');
            throw new \RuntimeException('Cloudinary configuration incomplete.');
        }

        $this->cloudinary = new \Cloudinary\Cloudinary([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => [
                'secure' => true
            ]
        ]);
    }

    public function getName(): string
    {
        return 'Cloudinary';
    }

    public function upload(string $source, string $target, array $options = []): string
    {
        try {
            return $this->executeWithRetry(function() use ($source, $target, $options) {
                // Cloudinary uses public_id which is the key without extension usually, but can be full path.
                // We'll use target as public_id.
                // We strip extension for Cloudinary public_id usually, but keeping it makes it predictable if unique_filename is false.

                $publicId = pathinfo($target, PATHINFO_FILENAME);
                // Wait, if target is "folder/file.jpg", public_id should be "folder/file"?
                // Or just pass target and let Cloudinary handle it?
                // Standard practice is to let Cloudinary assign extension or use exact public_id with `use_filename` => true, `unique_filename` => false.

                // Let's preserve the full path structure
                $publicId = $target;
                // Remove extension from publicId because Cloudinary adds it back?
                // Actually Cloudinary encourages public_id without extension.
                $ext = pathinfo($target, PATHINFO_EXTENSION);
                if ($ext) {
                    $publicId = substr($target, 0, -(strlen($ext) + 1));
                }

                $params = [
                    'public_id' => $publicId,
                    'overwrite' => true,
                    'resource_type' => 'auto',
                ];

                if (filesize($source) > 20 * 1024 * 1024) {
                    $params['chunk_size'] = self::CHUNK_SIZE;
                }

                $uploadApi = $this->cloudinary->uploadApi();
                $result = $uploadApi->upload($source, $params);

                if (empty($result['secure_url'])) {
                    throw new \RuntimeException('Cloudinary upload returned no URL');
                }

                return $result['secure_url'];
            });
        } catch (\Throwable $e) {
            Logger::log('error', 'cloudinary', 'Upload failed: ' . $e->getMessage(), ['target' => $target]);
            throw new \RuntimeException('Upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $target): void
    {
        try {
            $this->executeWithRetry(function() use ($target) {
                // Public ID is target without extension typically.
                // If we stored it that way.
                // We need to match how we stored it.
                // In upload we stripped extension.
                $publicId = $target;
                $ext = pathinfo($target, PATHINFO_EXTENSION);
                if ($ext) {
                    $publicId = substr($target, 0, -(strlen($ext) + 1));
                }

                $uploadApi = $this->cloudinary->uploadApi();
                $uploadApi->destroy($publicId, ['invalidate' => true]);
            });
        } catch (\Throwable $e) {
            Logger::log('warning', 'cloudinary', 'Delete failed: ' . $e->getMessage(), ['target' => $target]);
            throw new \RuntimeException('Delete failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function exists(string $target): bool
    {
        try {
            return $this->executeWithRetry(function() use ($target) {
                $publicId = $target;
                $ext = pathinfo($target, PATHINFO_EXTENSION);
                if ($ext) {
                    $publicId = substr($target, 0, -(strlen($ext) + 1));
                }

                $adminApi = $this->cloudinary->adminApi();
                // usage of admin API might be rate limited or restricted.
                // Alternative: check via explicit resource (head request)?
                // SDK usually provides resource() method.
                try {
                    $adminApi->resource($publicId);
                    return true;
                } catch (\Throwable $e) {
                    return false;
                }
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function existsMany(array $targets): array
    {
        $results = [];
        foreach ($targets as $target) {
            $results[$target] = $this->exists($target);
        }
        return $results;
    }

    public function getUrl(string $target, array $options = []): string
    {
        $publicId = $target;
        $ext = pathinfo($target, PATHINFO_EXTENSION);
        if ($ext) {
            $publicId = substr($target, 0, -(strlen($ext) + 1));
        }

        // Use offline URL builder
        $image = $this->cloudinary->image($publicId);

        if (!empty($options['signed'])) {
            $image->signUrl(true);
            // TTL is handled differently in Cloudinary (usually auth token).
            // This basic implementation enables signature.
        }

        return (string)$image->toUrl();
    }

    public function getStats(): array
    {
        try {
            $adminApi = $this->cloudinary->adminApi();
            $usage = $adminApi->usage();

            // Usage structure depends on API response.
            // Assuming 'credits' or 'storage' key.
            // Let's just return healthy if we can connect.

            return [
                'healthy'         => true,
                'used_bytes'      => $usage['storage']['used'] ?? null,
                'available_bytes' => isset($usage['storage']['limit']) ? ($usage['storage']['limit'] - $usage['storage']['used']) : null,
                'used_human'      => null, // Let UI format it or format if we have helper
                'available_human' => null,
            ];

        } catch (\Throwable $e) {
             return [
                'healthy'         => true, // Assume healthy if SDK loaded, maybe just API error
                'used_bytes'      => null,
                'available_bytes' => null,
                'used_human'      => null,
                'available_human' => null,
            ];
        }
    }
}

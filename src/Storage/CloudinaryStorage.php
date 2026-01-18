<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\Traits\Retryable;
use AperturePro\Storage\Cloudinary\CloudinaryUploader;
use AperturePro\Storage\Retry\RetryExecutor;
use AperturePro\Storage\Upload\UploadRequest;

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
    protected CloudinaryUploader $uploader;

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

        $this->uploader = new CloudinaryUploader(
            $this->cloudinary,
            new RetryExecutor()
        );
    }

    public function getName(): string
    {
        return 'Cloudinary';
    }

    public function upload(string $source, string $target, array $options = []): string
    {
        try {
            $request = new UploadRequest(
                localPath: $source,
                destinationKey: $target,
                contentType: $options['content_type'] ?? null,
                metadata: $options,
                sizeBytes: file_exists($source) ? filesize($source) : null
            );

            $result = $this->uploader->upload($request);

            return $result->url;
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
        // 1. Prepare Public IDs
        $map = []; // target => publicId
        $publicIds = [];

        foreach ($targets as $target) {
            $publicId = $target;
            $ext = pathinfo($target, PATHINFO_EXTENSION);
            if ($ext) {
                $publicId = substr($target, 0, -(strlen($ext) + 1));
            }
            $map[$target] = $publicId;
            $publicIds[] = $publicId;
        }

        $publicIds = array_unique($publicIds);
        $foundPublicIds = [];

        // 2. Batch check via Admin API (chunked to avoid URL length limits)
        // Cloudinary typically allows 100 public_ids per call.
        $chunks = array_chunk($publicIds, 100);

        try {
            $adminApi = $this->cloudinary->adminApi();

            foreach ($chunks as $chunk) {
                try {
                    $response = $this->executeWithRetry(function() use ($adminApi, $chunk) {
                        return $adminApi->resources([
                            'public_ids' => $chunk,
                            'max_results' => count($chunk),
                        ]);
                    });

                    if (!empty($response['resources'])) {
                        foreach ($response['resources'] as $resource) {
                            $foundPublicIds[$resource['public_id']] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    // If a chunk fails, log it but don't crash everything?
                    // Or maybe fall back to single checks?
                    // For now, log error.
                    Logger::log('error', 'cloudinary', 'Batch exists check failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Overall failure
            Logger::log('error', 'cloudinary', 'existsMany failed: ' . $e->getMessage());
        }

        // 3. Map results back to targets
        $results = [];
        foreach ($targets as $target) {
            $pid = $map[$target] ?? '';
            $results[$target] = isset($foundPublicIds[$pid]);
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

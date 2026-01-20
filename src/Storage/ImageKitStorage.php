<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\Traits\Retryable;
use AperturePro\Storage\ImageKit\ImageKitUploader;
use AperturePro\Storage\Retry\RetryExecutor;
use AperturePro\Storage\Chunking\ChunkedUploader;
use AperturePro\Storage\Upload\UploadRequest;

/**
 * ImageKitStorage
 *
 * Minimal ImageKit driver.
 */
class ImageKitStorage extends AbstractStorage
{
    use Retryable;

    protected array $config;
    protected $client;
    protected ImageKitUploader $uploader;

    public function __construct(array $config = [])
    {
        $this->config = $config;

        if (!class_exists('\ImageKit\ImageKit')) {
            Logger::log('error', 'imagekit', 'ImageKit SDK not found');
            throw new \RuntimeException('ImageKit SDK not installed. Please require imagekit/imagekit via Composer.');
        }

        $publicKey  = $config['public_key'] ?? '';
        $privateKey = $config['private_key'] ?? '';
        $urlEndpoint = $config['url_endpoint'] ?? '';

        if (empty($publicKey) || empty($privateKey) || empty($urlEndpoint)) {
            Logger::log('error', 'imagekit', 'ImageKit configuration incomplete', $config);
            throw new \RuntimeException('ImageKit configuration incomplete.');
        }

        $this->client = new \ImageKit\ImageKit($publicKey, $privateKey, $urlEndpoint);

        $this->uploader = new ImageKitUploader(
            $this->client,
            new RetryExecutor(),
            new ChunkedUploader()
        );
    }

    public function getName(): string
    {
        return 'ImageKit';
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
            $url = $result->url;

            if (empty($url)) {
                // Fallback to constructing URL from endpoint and object key if URL is missing in response
                if (!empty($result->objectKey)) {
                    return rtrim($this->config['url_endpoint'], '/') . '/' . ltrim($result->objectKey, '/');
                }
                throw new \RuntimeException('ImageKit upload returned no URL');
            }

            return $url;
        } catch (\Throwable $e) {
            Logger::log('error', 'imagekit', 'Upload failed: ' . $e->getMessage(), ['target' => $target]);
            throw new \RuntimeException('Upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getUrl(string $target, array $options = []): string
    {
        $path = ltrim($target, '/');
        $urlEndpoint = $this->config['url_endpoint'] ?? '';

        if (empty($urlEndpoint)) {
            throw new \RuntimeException('ImageKit URL endpoint not configured.');
        }

        // If signed URL requested, ImageKit supports URL signing via SDK or manual signing.
        if (!empty($options['signed']) && !empty($this->config['private_key'])) {
            // Basic signed URL generation using ImageKit SDK if available
            if (method_exists($this->client, 'url')) {
                return $this->client->url([
                    'path' => '/' . $path,
                    'expireSeconds' => $options['expires'] ?? 3600,
                ]);
            }
        }

        return rtrim($urlEndpoint, '/') . '/' . $path;
    }

    public function delete(string $target): void
    {
        try {
            $this->executeWithRetry(function() use ($target) {
                // ImageKit delete requires file ID usually, but target is path/key.
                // We assume target is used as ID here as per existing implementation.
                $fileId = $target;
                $this->client->deleteFile($fileId);
            });
        } catch (\Throwable $e) {
            Logger::log('warning', 'imagekit', 'Delete failed: ' . $e->getMessage(), ['target' => $target]);
            throw new \RuntimeException('Delete failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function exists(string $target): bool
    {
        try {
            return $this->executeWithRetry(function() use ($target) {
                $fileId = $target;
                $response = $this->client->getFileDetails($fileId);
                return !empty($response);
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function existsMany(array $targets): array
    {
        $results = [];
        foreach ($targets as $target) {
            $results[$target] = false;
        }

        // Group targets by directory to use path-scoped searching
        $byDir = [];
        foreach ($targets as $target) {
            $dir = dirname($target);
            if ($dir === '.' || $dir === '\\') {
                $dir = '';
            }
            // Normalize: ImageKit paths usually start with /
            if ($dir !== '' && $dir !== '/' && strpos($dir, '/') !== 0) {
                $dir = '/' . $dir;
            }
            // Ensure root is just /
            if ($dir === '') {
                $dir = '/';
            }
            $byDir[$dir][] = $target;
        }

        foreach ($byDir as $dir => $files) {
            // Chunk files to avoid exceeding URL/query limits
            $chunks = array_chunk($files, 20);

            foreach ($chunks as $chunk) {
                try {
                    // Construct search query: name="file1.jpg" OR name="file2.jpg"
                    $names = array_map(fn($f) => basename($f), $chunk);
                    $queryParts = [];
                    foreach ($names as $name) {
                        // Simple quote escaping for safety
                        $safeName = str_replace('"', '\"', $name);
                        $queryParts[] = 'name="' . $safeName . '"';
                    }
                    $searchQuery = implode(' OR ', $queryParts);

                    // Use listFiles with path scope and search query
                    $response = $this->client->listFiles([
                        'path' => $dir,
                        'searchQuery' => $searchQuery,
                        // Add a small buffer to limit just in case of duplicates or odd behavior
                        'limit' => count($chunk) + 5
                    ]);

                    // Parse response
                    $foundItems = [];
                    // Handle different potential response structures (array or object wrapper)
                    if (is_object($response) && isset($response->result)) {
                        $foundItems = $response->result;
                    } elseif (is_array($response)) {
                        $foundItems = $response;
                    } elseif (is_object($response)) {
                        // Some SDKs return iterable object
                        $foundItems = $response;
                    }

                    if ($foundItems) {
                        foreach ($foundItems as $item) {
                            $itemName = is_object($item) ? ($item->name ?? '') : ($item['name'] ?? '');
                            if ($itemName) {
                                // Match back to targets in this chunk
                                foreach ($chunk as $target) {
                                    if (basename($target) === $itemName) {
                                        $results[$target] = true;
                                    }
                                }
                            }
                        }
                    }

                } catch (\Throwable $e) {
                    Logger::log('warning', 'imagekit', 'Batch exists check failed, falling back to sequential', ['error' => $e->getMessage()]);
                    // Fallback to sequential checks for this chunk
                    foreach ($chunk as $target) {
                        $results[$target] = $this->exists($target);
                    }
                }
            }
        }

        return $results;
    }

    public function getStats(): array
    {
        return [
            'healthy'         => true,
            'used_bytes'      => null,
            'available_bytes' => null,
            'used_human'      => null,
            'available_human' => null,
        ];
    }

    protected function signInternal(string $path): ?string
    {
        return $this->getUrl($path, ['signed' => true, 'expires' => 300]);
    }

    protected function signManyInternal(array $paths): array
    {
        $results = [];
        foreach ($paths as $path) {
            $results[$path] = $this->signInternal($path);
        }
        return $results;
    }
}

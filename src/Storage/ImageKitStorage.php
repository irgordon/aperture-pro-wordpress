<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;

/**
 * ImageKitStorage
 *
 * Minimal ImageKit driver. This implementation expects the ImageKit PHP SDK
 * to be available (imagekit/imagekit). If the SDK is not installed, the driver
 * will throw an exception on construction.
 *
 * NOTE: This is a pragmatic implementation for common operations. For full
 * production usage you should expand error handling, retries, and multipart
 * upload support if needed.
 */
class ImageKitStorage implements StorageInterface
{
    protected array $config;
    protected $client;

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
    }

    public function upload(string $localPath, string $remoteKey, array $options = []): array
    {
        try {
            $fileName = basename($remoteKey);
            $folder = dirname($remoteKey);
            if ($folder === '.' || $folder === '/') {
                $folder = '';
            }

            $params = [
                'file' => base64_encode(file_get_contents($localPath)),
                'fileName' => $fileName,
            ];

            if (!empty($folder)) {
                $params['folder'] = $folder;
            }

            if (!empty($options['tags'])) {
                $params['tags'] = $options['tags'];
            }

            $response = $this->client->upload($params);

            if (empty($response) || empty($response->filePath)) {
                Logger::log('error', 'imagekit', 'ImageKit upload returned unexpected response', ['remoteKey' => $remoteKey]);
                return ['success' => false, 'key' => $remoteKey, 'url' => null, 'meta' => []];
            }

            $url = $response->url ?? ($this->client->getUrlEndpoint() . $response->filePath);

            return [
                'success' => true,
                'key'     => ltrim($response->filePath, '/'),
                'url'     => $url,
                'meta'    => (array) $response,
            ];
        } catch (\Throwable $e) {
            Logger::log('error', 'imagekit', 'Upload failed: ' . $e->getMessage(), ['remoteKey' => $remoteKey]);
            return ['success' => false, 'key' => $remoteKey, 'url' => null, 'meta' => []];
        }
    }

    public function getUrl(string $remoteKey, array $options = []): ?string
    {
        $path = ltrim($remoteKey, '/');
        $urlEndpoint = $this->config['url_endpoint'] ?? '';

        if (empty($urlEndpoint)) {
            return null;
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

    public function delete(string $remoteKey): bool
    {
        try {
            $fileId = $remoteKey;
            $response = $this->client->deleteFile($fileId);
            return true;
        } catch (\Throwable $e) {
            Logger::log('warning', 'imagekit', 'Delete failed: ' . $e->getMessage(), ['remoteKey' => $remoteKey]);
            return false;
        }
    }

    public function exists(string $remoteKey): bool
    {
        try {
            $fileId = $remoteKey;
            $response = $this->client->getFileDetails($fileId);
            return !empty($response);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function list(string $prefix = '', array $options = []): array
    {
        // ImageKit does not provide a simple list API in all plans; implement a best-effort approach.
        // For now, return empty and log that listing is not implemented.
        Logger::log('info', 'imagekit', 'List operation not implemented for ImageKit driver', ['prefix' => $prefix]);
        return [];
    }
}

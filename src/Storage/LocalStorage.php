<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;

/**
 * LocalStorage
 *
 * Stores files in the WordPress uploads directory. This driver is synchronous
 * and intended for small to medium files. For large files or production usage,
 * prefer a cloud-backed driver.
 */
class LocalStorage implements StorageInterface
{
    protected array $config;
    protected string $baseDir;
    protected string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->config = $config;

        $uploads = wp_upload_dir();
        $this->baseDir = trailingslashit($uploads['basedir']) . ($config['path'] ?? 'aperture-pro/');
        $this->baseUrl = trailingslashit($uploads['baseurl']) . ($config['path'] ?? 'aperture-pro/');

        if (!file_exists($this->baseDir)) {
            wp_mkdir_p($this->baseDir);
        }
    }

    public function upload(string $localPath, string $remoteKey, array $options = []): array
    {
        $dest = $this->baseDir . ltrim($remoteKey, '/');

        $destDir = dirname($dest);
        if (!file_exists($destDir)) {
            wp_mkdir_p($destDir);
        }

        $success = @copy($localPath, $dest);
        if (!$success) {
            Logger::log('error', 'local_storage', 'Failed to copy file to local storage', ['src' => $localPath, 'dest' => $dest]);
            return ['success' => false, 'key' => $remoteKey, 'url' => null, 'meta' => []];
        }

        // Optionally set file permissions
        @chmod($dest, 0644);

        $url = $this->getUrl($remoteKey);

        return [
            'success' => true,
            'key'     => $remoteKey,
            'url'     => $url,
            'meta'    => [
                'path' => $dest,
                'size' => filesize($dest),
            ],
        ];
    }

    public function getUrl(string $remoteKey, array $options = []): ?string
    {
        return $this->baseUrl . ltrim($remoteKey, '/');
    }

    public function delete(string $remoteKey): bool
    {
        $path = $this->baseDir . ltrim($remoteKey, '/');
        if (!file_exists($path)) {
            return true;
        }

        $deleted = @unlink($path);
        if (!$deleted) {
            Logger::log('warning', 'local_storage', 'Failed to delete file', ['path' => $path]);
        }

        return (bool) $deleted;
    }

    public function exists(string $remoteKey): bool
    {
        $path = $this->baseDir . ltrim($remoteKey, '/');
        return file_exists($path);
    }

    public function list(string $prefix = '', array $options = []): array
    {
        $dir = $this->baseDir . ltrim($prefix, '/');
        $results = [];

        if (!is_dir($dir)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace($this->baseDir, '', $file->getPathname());
                $results[] = [
                    'key'  => ltrim($relative, '/'),
                    'size' => $file->getSize(),
                    'mtime'=> date('c', $file->getMTime()),
                ];
            }
        }

        return $results;
    }
}

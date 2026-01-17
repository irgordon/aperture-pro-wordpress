<?php

namespace AperturePro\Storage;

use AperturePro\Helpers\Logger;
use AperturePro\Storage\Traits\Retryable;

/**
 * LocalStorage
 *
 * Stores files in the WordPress uploads directory. This driver hides server
 * file paths by issuing signed, single-use tokens that map to the real file.
 * The token is returned as a REST URL which the DownloadController serves.
 *
 * Security:
 *  - Raw server paths are never returned to clients.
 *  - Tokens are single-use and time-limited (TTL).
 *  - Tokens can be bound to client IP addresses to reduce token theft risk.
 *
 * Performance / failure policy:
 *  - Failures are logged via Logger::log and will trigger admin notification
 *    when appropriate.
 */
class LocalStorage implements StorageInterface
{
    use Retryable;

    protected array $config;
    protected string $baseDir;
    protected string $baseUrl;
    protected int $tokenTtl;
    protected bool $bindIp;

    const TRANSIENT_PREFIX = 'ap_local_file_';
    const TOKEN_BYTES = 32;

    public function __construct(array $config = [])
    {
        $this->config = $config;

        $uploads = wp_upload_dir();
        $path = $config['path'] ?? 'aperture-pro/';
        $this->baseDir = trailingslashit($uploads['basedir']) . $path;
        $this->baseUrl = trailingslashit($uploads['baseurl']) . $path;

        $this->tokenTtl = (int) ($config['signed_url_ttl'] ?? 3600);
        $this->bindIp = !empty($config['bind_token_to_ip']);

        if (!file_exists($this->baseDir)) {
            $created = wp_mkdir_p($this->baseDir);
            if (!$created) {
                Logger::log('error', 'local_storage', 'Failed to create base storage directory', ['path' => $this->baseDir, 'notify_admin' => true]);
            }
        }
    }

    public function getName(): string
    {
        return 'Local';
    }

    /**
     * Upload a local file into the storage area.
     *
     * @param string $source  Absolute path to local temp file or content.
     * @param string $target  Relative path/key in the storage backend.
     * @param array  $options Optional driver-specific options.
     *
     * @return string Public or signed URL to the stored file.
     * @throws \RuntimeException If upload fails.
     */
    public function upload(string $source, string $target, array $options = []): string
    {
        try {
            return $this->executeWithRetry(function() use ($source, $target, $options) {
                $dest = $this->baseDir . ltrim($target, '/');

                $destDir = dirname($dest);
                if (!file_exists($destDir)) {
                    $created = wp_mkdir_p($destDir);
                    if (!$created) {
                        throw new \RuntimeException("Failed to create destination directory: $destDir");
                    }
                }

                $success = @copy($source, $dest);
                if (!$success) {
                    throw new \RuntimeException("Failed to copy file to local storage: $source -> $dest");
                }

                @chmod($dest, 0644);

                return $this->getUrl($target, $options);
            });
        } catch (\Throwable $e) {
            Logger::log('error', 'local_storage', 'Upload failed: ' . $e->getMessage(), ['src' => $source, 'notify_admin' => true]);
            throw new \RuntimeException('Upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns a signed REST URL that maps to the file.
     *
     * @param string $target
     * @param array  $options
     * @return string
     * @throws \RuntimeException
     */
    public function getUrl(string $target, array $options = []): string
    {
        try {
            return $this->executeWithRetry(function() use ($target, $options) {
                $path = $this->baseDir . ltrim($target, '/');

                // Generate signed token even if file doesn't exist yet (for lazy checks or consistent interface)
                // But strictly speaking, we need path to map.

                $token = $this->createSignedTokenForPath($path, $target, $options);
                if (!$token) {
                    throw new \RuntimeException("Failed to create signed token for getUrl");
                }

                return $this->buildSignedUrl($token);
            });
        } catch (\Throwable $e) {
            Logger::log('error', 'local_storage', 'getUrl failed: ' . $e->getMessage(), ['key' => $target]);
            // Contract says return string. We should throw if we can't generate a URL.
            throw $e;
        }
    }

    /**
     * Create a transient token mapping to the real file path and metadata.
     */
    protected function createSignedTokenForPath(string $path, string $remoteKey, array $options = []): ?string
    {
        try {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $e) {
            $token = hash('sha256', uniqid('ap_', true));
        }

        $clientIp = $this->getClientIp();
        $bindIp = $options['bind_ip'] ?? $this->bindIp;

        $payload = [
            'path'       => $path,
            'key'        => $remoteKey,
            'mime'       => $this->detectMimeType($path), // Might be inaccurate if file doesn't exist, will fallback
            'created_at' => time(),
            'expires_at' => time() + ($options['expires'] ?? $options['ttl'] ?? $this->tokenTtl),
            'inline'     => $options['inline'] ?? false,
            'bind_ip'    => $bindIp,
            'ip'         => $bindIp ? $clientIp : null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        $saved = set_transient(self::TRANSIENT_PREFIX . $token, $payload, $payload['expires_at'] - time());
        if (!$saved) {
            Logger::log('error', 'local_storage', 'Failed to save signed token transient', ['token' => $token, 'path' => $path]);
            return null;
        }

        return $token;
    }

    protected function buildSignedUrl(string $token): string
    {
        $restBase = rest_url('aperture/v1/local-file/' . $token);
        $httpsUrl = set_url_scheme($restBase, 'https');
        return esc_url_raw($httpsUrl);
    }

    /**
     * Delete a stored object.
     */
    public function delete(string $target): void
    {
        try {
            $this->executeWithRetry(function() use ($target) {
                $path = $this->baseDir . ltrim($target, '/');
                if (!file_exists($path)) {
                    return;
                }

                $deleted = @unlink($path);
                if (!$deleted) {
                    throw new \RuntimeException("Failed to delete file: $path");
                }
            });
        } catch (\Throwable $e) {
            Logger::log('warning', 'local_storage', 'Delete failed: ' . $e->getMessage(), ['path' => $target, 'notify_admin' => true]);
            throw $e;
        }
    }

    public function exists(string $target): bool
    {
        try {
             return $this->executeWithRetry(function() use ($target) {
                 $path = $this->baseDir . ltrim($target, '/');
                 return file_exists($path);
             });
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getStats(): array
    {
        try {
            $free  = @disk_free_space($this->baseDir);
            $total = @disk_total_space($this->baseDir);

            if ($free === false || $total === false) {
                return [
                    'healthy'         => true,
                    'used_bytes'      => null,
                    'available_bytes' => null,
                    'used_human'      => null,
                    'available_human' => null,
                ];
            }

            $used      = $total - $free;
            $usedHuman = size_format($used);
            $freeHuman = size_format($free);

            return [
                'healthy'         => true,
                'used_bytes'      => $used,
                'available_bytes' => $free,
                'used_human'      => $usedHuman,
                'available_human' => $freeHuman,
            ];
        } catch (\Throwable $e) {
            Logger::log('error', 'local_storage', 'Stats failed: ' . $e->getMessage());
            return [
                'healthy'         => false,
                'used_bytes'      => null,
                'available_bytes' => null,
                'used_human'      => null,
                'available_human' => null,
            ];
        }
    }

    /**
     * Get the absolute local path for a key, if it exists.
     */
    public function getLocalPath(string $target): ?string
    {
        $path = $this->baseDir . ltrim($target, '/');
        return file_exists($path) ? $path : null;
    }

    protected function detectMimeType(string $path): string
    {
        $mime = null;
        if (file_exists($path)) {
            if (function_exists('mime_content_type')) {
                $mime = @mime_content_type($path);
            }
            if (empty($mime) && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = finfo_file($finfo, $path);
                    finfo_close($finfo);
                }
            }
        }
        return $mime ?: 'application/octet-stream';
    }

    protected function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $first = trim($parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        return $ip;
    }
}

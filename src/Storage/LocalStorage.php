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
 *    when appropriate (see Logger::log).
 *  - This class does not throw for routine failures; it returns structured
 *    responses and logs details for admin attention.
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

    /**
     * Upload a local file into the storage area.
     *
     * Returns a structured array. On success 'url' will be a signed REST URL
     * that can be used to retrieve the file. The raw server path is never returned.
     */
    public function upload(string $localPath, string $remoteKey, array $options = []): array
    {
        try {
            return $this->executeWithRetry(function() use ($localPath, $remoteKey, $options) {
                $dest = $this->baseDir . ltrim($remoteKey, '/');

                $destDir = dirname($dest);
                if (!file_exists($destDir)) {
                    $created = wp_mkdir_p($destDir);
                    if (!$created) {
                        throw new \RuntimeException("Failed to create destination directory: $destDir");
                    }
                }

                $success = @copy($localPath, $dest);
                if (!$success) {
                    throw new \RuntimeException("Failed to copy file to local storage: $localPath -> $dest");
                }

                @chmod($dest, 0644);

                // Create a signed URL for immediate access if requested, otherwise return key and meta.
                $signed = $options['signed'] ?? true;
                $url = null;
                if ($signed) {
                    $token = $this->createSignedTokenForPath($dest, $remoteKey, $options);
                    if ($token) {
                        $url = $this->buildSignedUrl($token);
                    } else {
                        // Token creation failed; trigger retry
                        throw new \RuntimeException("Signed token creation failed after upload");
                    }
                }

                return [
                    'success' => true,
                    'key'     => $remoteKey,
                    'url'     => $url,
                    'meta'    => [
                        'path' => $dest,
                        'size' => filesize($dest),
                    ],
                ];
            });
        } catch (\Throwable $e) {
            Logger::log('error', 'local_storage', 'Upload failed: ' . $e->getMessage(), ['src' => $localPath, 'notify_admin' => true]);
            return ['success' => false, 'key' => $remoteKey, 'url' => null, 'meta' => []];
        }
    }

    /**
     * Store a file from a local path (Alias/Compat).
     *
     * @param string $localPath
     * @param string $remotePath
     * @param array  $options
     * @return bool
     */
    public function putFile(string $localPath, string $remotePath, array $options = []): bool
    {
        $result = $this->upload($localPath, $remotePath, $options);
        return $result['success'];
    }

    /**
     * Returns a signed REST URL that maps to the file. The URL does not reveal
     * the server path. The token is single-use and expires after tokenTtl seconds.
     */
    public function getUrl(string $remoteKey, array $options = []): ?string
    {
        try {
            return $this->executeWithRetry(function() use ($remoteKey, $options) {
                $path = $this->baseDir . ltrim($remoteKey, '/');

                if (!file_exists($path)) {
                    return null;
                }

                $token = $this->createSignedTokenForPath($path, $remoteKey, $options);
                if (!$token) {
                    throw new \RuntimeException("Failed to create signed token for getUrl");
                }

                return $this->buildSignedUrl($token);
            });
        } catch (\Throwable $e) {
            Logger::log('error', 'local_storage', 'getUrl failed: ' . $e->getMessage(), ['key' => $remoteKey]);
            return null;
        }
    }

    /**
     * Create a transient token mapping to the real file path and metadata.
     *
     * Returns token string on success or null on failure.
     *
     * Options:
     *  - ttl: override TTL in seconds
     *  - inline: bool (serve inline vs attachment)
     *  - bind_ip: bool override default binding behavior
     */
    protected function createSignedTokenForPath(string $path, string $remoteKey, array $options = []): ?string
    {
        try {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $e) {
            // Fallback to less secure uniqid if random_bytes fails (very unlikely)
            $token = hash('sha256', uniqid('ap_', true));
        }

        $clientIp = $this->getClientIp();
        $bindIp = $options['bind_ip'] ?? $this->bindIp;

        $payload = [
            'path'       => $path,
            'key'        => $remoteKey,
            'mime'       => $this->detectMimeType($path),
            'created_at' => time(),
            'expires_at' => time() + ($options['ttl'] ?? $this->tokenTtl),
            'inline'     => $options['inline'] ?? false,
            'bind_ip'    => $bindIp,
            'ip'         => $bindIp ? $clientIp : null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        $saved = set_transient(self::TRANSIENT_PREFIX . $token, $payload, $payload['expires_at'] - time());
        if (!$saved) {
            Logger::log('error', 'local_storage', 'Failed to save signed token transient', ['token' => $token, 'path' => $path, 'notify_admin' => true]);
            return null;
        }

        return $token;
    }

    /**
     * Build the REST URL that will serve the file for the given token.
     *
     * Enforces HTTPS scheme for the returned URL.
     */
    protected function buildSignedUrl(string $token): string
    {
        // Use the plugin REST namespace route implemented in DownloadController
        $restBase = rest_url('aperture/v1/local-file/' . $token);

        // Force https for signed URLs to ensure tokens are not leaked over plain HTTP.
        $httpsUrl = set_url_scheme($restBase, 'https');

        return esc_url_raw($httpsUrl);
    }

    /**
     * Delete a stored object.
     */
    public function delete(string $remoteKey): bool
    {
        try {
            return $this->executeWithRetry(function() use ($remoteKey) {
                $path = $this->baseDir . ltrim($remoteKey, '/');
                if (!file_exists($path)) {
                    return true;
                }

                $deleted = @unlink($path);
                if (!$deleted) {
                     // Retryable
                    throw new \RuntimeException("Failed to delete file: $path");
                }

                return true;
            });
        } catch (\Throwable $e) {
            Logger::log('warning', 'local_storage', 'Delete failed: ' . $e->getMessage(), ['path' => $remoteKey, 'notify_admin' => true]);
            return false;
        }
    }

    public function exists(string $remoteKey): bool
    {
        try {
             return $this->executeWithRetry(function() use ($remoteKey) {
                 $path = $this->baseDir . ltrim($remoteKey, '/');
                 return file_exists($path);
             });
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the absolute local path for a key, if it exists.
     * This allows services to bypass HTTP calls when working with local files.
     */
    public function getLocalPath(string $remoteKey): ?string
    {
        $path = $this->baseDir . ltrim($remoteKey, '/');
        return file_exists($path) ? $path : null;
    }

    public function list(string $prefix = '', array $options = []): array
    {
        try {
            return $this->executeWithRetry(function() use ($prefix) {
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
            });
        } catch (\Throwable $e) {
            Logger::log('warning', 'local_storage', 'List failed: ' . $e->getMessage(), ['prefix' => $prefix]);
            return [];
        }
    }

    /**
     * Detect mime type for a file path.
     */
    protected function detectMimeType(string $path): string
    {
        $mime = null;

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

        if (empty($mime)) {
            // Fallback to generic binary
            $mime = 'application/octet-stream';
        }

        return $mime;
    }

    /**
     * Get client IP address, respecting common proxy headers.
     */
    protected function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Respect X-Forwarded-For if present (first IP)
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

<?php

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use AperturePro\Download\ZipStreamService;
use AperturePro\Helpers\Logger;

class DownloadController extends BaseController
{
    // Rate limiting settings
    const RATE_LIMIT_WINDOW = 60; // seconds
    const RATE_LIMIT_MAX = 10; // max requests per window per token/ip

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/download/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'download_zip'],
            'permission_callback' => '__return_true',
        ]);

        // New route: serve local files by signed token
        register_rest_route($this->namespace, '/local-file/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serve_local_file'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Existing ZIP download route (unchanged).
     */
    public function download_zip(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));

            if (empty($token)) {
                return $this->respond_error('missing_token', 'Download token is required.', 400);
            }

            $result = ZipStreamService::streamByToken($token);

            if (!is_array($result) || empty($result['success'])) {
                Logger::log(
                    'warning',
                    'download',
                    'Download failed or token invalid',
                    [
                        'token'  => $token,
                        'reason' => $result['message'] ?? ($result['reason'] ?? 'unknown'),
                    ]
                );

                return $this->respond_error(
                    $result['error'] ?? 'download_failed',
                    $result['message'] ?? 'This download link is no longer active.',
                    $result['status'] ?? 410
                );
            }

            return $this->respond_success(['message' => 'Download started.']);
        }, ['endpoint' => 'download_zip']);
    }

    /**
     * Serve a local file referenced by a signed transient token.
     *
     * This method streams the file directly and exits. It validates:
     *  - transient exists
     *  - not expired
     *  - file exists on disk
     *  - token bound IP matches request IP (if binding enabled)
     *  - rate limiting per token/IP
     *
     * On any critical failure, it logs and sends admin notification via Logger.
     *
     * Partial download / resumability:
     *  - This method includes a basic single-range implementation for "Range" header.
     *  - For production, consider robust support for multiple ranges and resume semantics.
     */
    public function serve_local_file(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            if (empty($token)) {
                return $this->respond_error('missing_token', 'Token is required.', 400);
            }

            $transientKey = 'ap_local_file_' . $token;
            $payload = get_transient($transientKey);

            if (empty($payload) || !is_array($payload)) {
                Logger::log('warning', 'download', 'Local file token invalid or expired', ['token' => $token]);
                return $this->respond_error('invalid_token', 'This link is no longer valid.', 410);
            }

            // Rate limiting: per-token and per-IP counters
            $clientIp = $this->getClientIp();
            $rateKeyToken = "ap_local_rate_token_{$token}";
            $rateKeyIp = "ap_local_rate_ip_" . md5($clientIp);

            $tokenCount = (int) get_transient($rateKeyToken);
            $ipCount = (int) get_transient($rateKeyIp);

            if ($tokenCount >= self::RATE_LIMIT_MAX || $ipCount >= self::RATE_LIMIT_MAX) {
                Logger::log('warning', 'download', 'Rate limit exceeded for token or IP', ['token' => $token, 'ip' => $clientIp]);
                return $this->respond_error('rate_limited', 'Too many requests. Please try again later.', 429);
            }

            // Increment counters (set TTL to window)
            set_transient($rateKeyToken, $tokenCount + 1, self::RATE_LIMIT_WINDOW);
            set_transient($rateKeyIp, $ipCount + 1, self::RATE_LIMIT_WINDOW);

            // Check expiry
            $now = time();
            if (!empty($payload['expires_at']) && $now > (int) $payload['expires_at']) {
                delete_transient($transientKey);
                Logger::log('warning', 'download', 'Local file token expired', ['token' => $token]);
                return $this->respond_error('expired_token', 'This link has expired.', 410);
            }

            // IP binding check
            if (!empty($payload['bind_ip'])) {
                $boundIp = $payload['ip'] ?? null;
                if ($boundIp && $boundIp !== $clientIp) {
                    Logger::log('warning', 'download', 'Token IP mismatch', ['token' => $token, 'expected_ip' => $boundIp, 'request_ip' => $clientIp]);
                    return $this->respond_error('ip_mismatch', 'This link is not valid from your network.', 403);
                }
            }

            $path = $payload['path'] ?? null;
            $mime = $payload['mime'] ?? 'application/octet-stream';
            $inline = !empty($payload['inline']);

            if (empty($path) || !file_exists($path)) {
                // Critical: token exists but file missing. Log and notify admin.
                Logger::log('error', 'download', 'Local file missing for token', ['token' => $token, 'path' => $path, 'notify_admin' => true]);
                delete_transient($transientKey);
                return $this->respond_error('file_missing', 'The requested file is no longer available.', 410);
            }

            // Delete the transient to make token single-use
            delete_transient($transientKey);

            // Prepare streaming with support for Range header (basic single-range)
            $filesize = filesize($path);
            $start = 0;
            $end = $filesize - 1;
            $statusCode = 200;
            $headers = [];

            // Handle Range header for resumable downloads (basic single-range support)
            $rangeHeader = $_SERVER['HTTP_RANGE'] ?? $_SERVER['REDIRECT_HTTP_RANGE'] ?? null;
            if ($rangeHeader) {
                // Example: "Range: bytes=0-1023"
                if (preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
                    $rangeStart = $matches[1] !== '' ? intval($matches[1]) : null;
                    $rangeEnd = $matches[2] !== '' ? intval($matches[2]) : null;

                    if ($rangeStart !== null && $rangeEnd !== null) {
                        $start = max(0, $rangeStart);
                        $end = min($filesize - 1, $rangeEnd);
                    } elseif ($rangeStart !== null) {
                        $start = max(0, $rangeStart);
                        $end = $filesize - 1;
                    } elseif ($rangeEnd !== null) {
                        // suffix-length: last N bytes
                        $length = $rangeEnd;
                        $start = max(0, $filesize - $length);
                        $end = $filesize - 1;
                    }

                    if ($start > $end || $start >= $filesize) {
                        // Invalid range
                        Logger::log('warning', 'download', 'Invalid Range header', ['range' => $rangeHeader, 'path' => $path]);
                        return $this->respond_error('invalid_range', 'Requested Range Not Satisfiable', 416);
                    }

                    $statusCode = 206; // Partial Content
                    $headers['Content-Range'] = "bytes {$start}-{$end}/{$filesize}";
                }
            }

            $length = ($end - $start) + 1;

            // Clear output buffers to avoid corrupting the stream
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set headers
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            $filename = basename($payload['key'] ?? $path);
            $disposition = $inline ? 'inline' : 'attachment';
            header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $length);
            if (!empty($headers['Content-Range'])) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: ' . $headers['Content-Range']);
            } else {
                header('HTTP/1.1 200 OK');
            }

            // Stream file in chunks to avoid memory blowout
            $chunkSize = 1024 * 1024; // 1MB
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                Logger::log('error', 'download', 'Failed to open file for streaming', ['path' => $path, 'notify_admin' => true]);
                return $this->respond_error('stream_error', 'Unable to stream file.', 500);
            }

            // Seek to start
            if (fseek($handle, $start) === -1) {
                Logger::log('error', 'download', 'Failed to seek file for streaming', ['path' => $path, 'start' => $start, 'notify_admin' => true]);
                fclose($handle);
                return $this->respond_error('stream_error', 'Unable to stream file.', 500);
            }

            $bytesRemaining = $length;

            try {
                while ($bytesRemaining > 0 && !feof($handle)) {
                    $read = ($bytesRemaining > $chunkSize) ? $chunkSize : $bytesRemaining;
                    $data = fread($handle, $read);
                    if ($data === false) {
                        throw new \RuntimeException('fread returned false');
                    }
                    echo $data;
                    $bytesRemaining -= strlen($data);

                    // Flush buffers
                    if (function_exists('fastcgi_finish_request')) {
                        @flush();
                    } else {
                        @flush();
                        @ob_flush();
                    }
                }
            } catch (\Throwable $e) {
                Logger::log('error', 'download', 'Error during file streaming: ' . $e->getMessage(), ['path' => $path, 'notify_admin' => true]);
                fclose($handle);
                return $this->respond_error('stream_error', 'Error during streaming.', 500);
            }

            fclose($handle);

            // After streaming, exit to prevent WP from appending HTML
            exit;
        }, ['endpoint' => 'download_local_file']);
    }

    /**
     * Get client IP address, respecting common proxy headers.
     */
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

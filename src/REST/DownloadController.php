<?php

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use AperturePro\Download\ZipStreamService;
use AperturePro\Helpers\Logger;

class DownloadController extends BaseController
{
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
     *
     * On any critical failure, it logs and sends admin notification via Logger.
     */
    public function serve_local_file(WP_REST_Request $request)
    {
        // We intentionally do not wrap the streaming block in the same response object,
        // because streaming requires sending headers and raw output.
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

            // Check expiry
            $now = time();
            if (!empty($payload['expires_at']) && $now > (int) $payload['expires_at']) {
                delete_transient($transientKey);
                Logger::log('warning', 'download', 'Local file token expired', ['token' => $token]);
                return $this->respond_error('expired_token', 'This link has expired.', 410);
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

            // Stream the file
            // Delete the transient to make token single-use
            delete_transient($transientKey);

            // Send headers
            $filename = basename($payload['key'] ?? $path);
            $disposition = $inline ? 'inline' : 'attachment';

            // Clear output buffers to avoid corrupting the stream
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set headers
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($path));

            // Stream file in chunks to avoid memory blowout
            $chunkSize = 1024 * 1024; // 1MB
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                Logger::log('error', 'download', 'Failed to open file for streaming', ['path' => $path, 'notify_admin' => true]);
                return $this->respond_error('stream_error', 'Unable to stream file.', 500);
            }

            // Output the file
            try {
                while (!feof($handle)) {
                    echo fread($handle, $chunkSize);
                    // Flush buffers
                    if (function_exists('fastcgi_finish_request')) {
                        // Let PHP finish request quickly if available
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
}

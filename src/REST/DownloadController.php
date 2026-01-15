<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Download\ZipStreamService;
use AperturePro\Helpers\Logger;
use AperturePro\Auth\CookieService;
use AperturePro\Email\EmailService;

/**
 * DownloadController
 *
 * - Serves ZIP downloads by token (token bound to project and optionally email).
 * - Serves local-file tokens via /local-file/{token} (implemented in LocalStorage and DownloadController earlier).
 * - Allows client portal to regenerate a download token for their project (7 day TTL).
 * - Enforces rate limiting and logs/queues admin notifications on critical failures.
 */
class DownloadController extends BaseController
{
    // Rate limiting settings (per IP and per token)
    const RATE_LIMIT_WINDOW = 60; // seconds
    const RATE_LIMIT_MAX = 10; // max requests per window per token/ip

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/download/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'download_zip'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/local-file/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serve_local_file'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/projects/(?P<project_id>\d+)/regenerate-download-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'regenerate_download_token'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Stream ZIP by token. Token must be valid and bound to project/email as appropriate.
     */
    public function download_zip(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            if (empty($token)) {
                return $this->respond_error('missing_token', 'Download token is required.', 400);
            }

            // Rate limiting per token and per IP
            $clientIp = $this->getClientIp();
            $rateKeyToken = "ap_download_rate_token_{$token}";
            $rateKeyIp = "ap_download_rate_ip_" . md5($clientIp);

            $tokenCount = (int) get_transient($rateKeyToken);
            $ipCount = (int) get_transient($rateKeyIp);

            if ($tokenCount >= self::RATE_LIMIT_MAX || $ipCount >= self::RATE_LIMIT_MAX) {
                Logger::log('warning', 'download', 'Rate limit exceeded for token or IP', ['token' => $token, 'ip' => $clientIp]);
                return $this->respond_error('rate_limited', 'Too many requests. Please try again later.', 429);
            }

            set_transient($rateKeyToken, $tokenCount + 1, self::RATE_LIMIT_WINDOW);
            set_transient($rateKeyIp, $ipCount + 1, self::RATE_LIMIT_WINDOW);

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

                // Update Health Card
                $this->updateHealthCard('download_failure', [
                    'token' => $token,
                    'reason' => $result['message'] ?? 'unknown',
                    'time' => current_time('mysql'),
                ]);

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
     * This route is used by LocalStorage signed URLs.
     */
    public function serve_local_file(WP_REST_Request $request)
    {
        // Implementation delegated to DownloadController earlier (streaming and rate limiting).
        // For consistency, call the same logic as before (see previous DownloadController implementation).
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

            // Rate limiting
            $clientIp = $this->getClientIp();
            $rateKeyToken = "ap_local_rate_token_{$token}";
            $rateKeyIp = "ap_local_rate_ip_" . md5($clientIp);

            $tokenCount = (int) get_transient($rateKeyToken);
            $ipCount = (int) get_transient($rateKeyIp);

            if ($tokenCount >= self::RATE_LIMIT_MAX || $ipCount >= self::RATE_LIMIT_MAX) {
                Logger::log('warning', 'download', 'Rate limit exceeded for token or IP', ['token' => $token, 'ip' => $clientIp]);
                return $this->respond_error('rate_limited', 'Too many requests. Please try again later.', 429);
            }

            set_transient($rateKeyToken, $tokenCount + 1, self::RATE_LIMIT_WINDOW);
            set_transient($rateKeyIp, $ipCount + 1, self::RATE_LIMIT_WINDOW);

            // Validate expiry and IP binding
            $now = time();
            if (!empty($payload['expires_at']) && $now > (int) $payload['expires_at']) {
                delete_transient($transientKey);
                Logger::log('warning', 'download', 'Local file token expired', ['token' => $token]);
                return $this->respond_error('expired_token', 'This link has expired.', 410);
            }

            if (!empty($payload['bind_ip'])) {
                $boundIp = $payload['ip'] ?? null;
                if ($boundIp && $boundIp !== $clientIp) {
                    Logger::log('warning', 'download', 'Token IP mismatch', ['token' => $token, 'expected_ip' => $boundIp, 'request_ip' => $clientIp]);
                    return $this->respond_error('ip_mismatch', 'This link is not valid from your network.', 403);
                }
            }

            $path = $payload['path'] ?? null;
            if (empty($path) || !file_exists($path)) {
                Logger::log('error', 'download', 'Local file missing for token', ['token' => $token, 'path' => $path, 'notify_admin' => true]);
                delete_transient($transientKey);
                EmailService::enqueueAdminNotification('error', 'download_missing_file', 'Local file missing for token', ['token' => $token, 'path' => $path]);
                return $this->respond_error('file_missing', 'The requested file is no longer available.', 410);
            }

            // Delete transient to make token single-use
            delete_transient($transientKey);

            // Stream file with Range support (see earlier implementation)
            // For brevity we delegate to a helper in DownloadController earlier; here we call it directly.
            // The helper will exit after streaming.
            return (new \AperturePro\REST\DownloadControllerHelper())->streamLocalFileResource($path, $payload);
        }, ['endpoint' => 'download_local_file']);
    }

    /**
     * Regenerate a download token for a project from the client portal.
     * Client must be authenticated via CookieService and project must match session.
     */
    public function regenerate_download_token(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $projectId = (int) $request['project_id'];
            if ($projectId <= 0) {
                return $this->respond_error('invalid_input', 'Invalid project id.', 400);
            }

            $session = CookieService::getClientSession();
            if (!$session || (int)$session['project_id'] !== $projectId) {
                return $this->respond_error('unauthorized', 'You do not have access to this project.', 403);
            }

            global $wpdb;
            $downloadsTable = $wpdb->prefix . 'ap_download_tokens';

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600)); // 7 days

            // Determine client email
            $clientsTable = $wpdb->prefix . 'ap_clients';
            $clientRow = $wpdb->get_row($wpdb->prepare("SELECT email FROM {$clientsTable} WHERE id = %d LIMIT 1", (int)$session['client_id']));
            $email = $clientRow ? $clientRow->email : null;

            $galleryId = $this->get_final_gallery_id_for_project($projectId);

            $inserted = $wpdb->insert(
                $downloadsTable,
                [
                    'gallery_id' => $galleryId,
                    'project_id' => $projectId,
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                    'created_at' => current_time('mysql'),
                    'email'      => $email,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                Logger::log('error', 'download', 'Failed to persist download token', ['project_id' => $projectId, 'notify_admin' => true]);
                return $this->respond_error('persist_failed', 'Could not create download token.', 500);
            }

            $transientKey = 'ap_download_' . $token;
            $payload = [
                'gallery_id' => $galleryId,
                'project_id' => $projectId,
                'email'      => $email,
                'created_at' => time(),
                'expires_at' => strtotime($expiresAt),
            ];
            set_transient($transientKey, $payload, 7 * 24 * 3600);

            $downloadUrl = add_query_arg('ap_download', $token, home_url('/'));

            Logger::log('info', 'download', 'Download token regenerated', ['project_id' => $projectId, 'token' => $token]);

            return $this->respond_success([
                'download_url' => $downloadUrl,
                'expires_at'   => $expiresAt,
            ]);
        }, ['endpoint' => 'regenerate_download_token']);
    }

    /**
     * Helper: get client IP (respecting common proxy headers).
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

<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Download\ZipStreamService;
use AperturePro\Helpers\Logger;
use AperturePro\Helpers\RateLimiter;
use AperturePro\Auth\CookieService;
use AperturePro\Auth\OTPService;
use AperturePro\Email\EmailService;

/**
 * DownloadController (updated)
 *
 * Enforces token binding to project and email, supports OTP verification, and ensures security checks.
 */
class DownloadController extends BaseController
{
    const RATE_LIMIT_WINDOW = 60; // seconds
    const RATE_LIMIT_MAX = 10; // max requests per window per token/ip

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/download/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'download_zip'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/projects/(?P<project_id>\d+)/regenerate-download-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'regenerate_download_token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/download/(?P<token>[a-f0-9]{64})/request-otp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_download_otp'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/download/verify-otp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'verify_download_otp'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Stream ZIP by token. Enforces binding to project and email and optional OTP verification.
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

            if (!RateLimiter::check($rateKeyToken, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW) ||
                !RateLimiter::check($rateKeyIp, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
                Logger::log('warning', 'download', 'Rate limit exceeded for token or IP', ['token' => $token, 'ip' => $clientIp]);
                return $this->respond_error('rate_limited', 'Too many requests. Please try again later.', 429);
            }

            // Resolve token payload
            $transientKey = 'ap_download_' . $token;
            $payload = get_transient($transientKey);

            // Fallback to DB if transient missing
            if (empty($payload)) {
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_download_tokens WHERE token = %s LIMIT 1", $token), ARRAY_A);
                if ($row) {
                    $payload = [
                        'gallery_id' => (int) $row['gallery_id'],
                        'project_id' => (int) $row['project_id'],
                        'email' => $row['email'] ?? null,
                        'created_at' => strtotime($row['created_at']),
                        'expires_at' => !empty($row['expires_at']) ? strtotime($row['expires_at']) : null,
                        'require_otp' => !empty($row['require_otp']) ? (bool)$row['require_otp'] : false,
                    ];
                }
            }

            if (empty($payload) || !is_array($payload)) {
                Logger::log('warning', 'download', 'Download token not found or expired', ['token' => $token]);
                return $this->respond_error('invalid_token', 'This link is no longer valid.', 410);
            }

            // Check expiry
            if (!empty($payload['expires_at']) && time() > (int)$payload['expires_at']) {
                delete_transient($transientKey);
                Logger::log('warning', 'download', 'Download token expired', ['token' => $token]);
                return $this->respond_error('expired_token', 'This link has expired.', 410);
            }

            // Verify client session and email binding
            $session = CookieService::getClientSession();
            if (!empty($payload['project_id'])) {
                if (!$session || (int)$session['project_id'] !== (int)$payload['project_id']) {
                    Logger::log('warning', 'download', 'Session project mismatch', ['token' => $token, 'session' => $session ?? null]);
                    return $this->respond_error('unauthorized', 'You do not have access to this download.', 403);
                }
            }

            if (!empty($payload['email'])) {
                // If session exists and has client email, compare; otherwise require OTP flow
                $sessionEmail = $session['email'] ?? null;
                if ($sessionEmail && strtolower($sessionEmail) !== strtolower($payload['email'])) {
                    Logger::log('warning', 'download', 'Session email mismatch', ['token' => $token, 'session_email' => $sessionEmail, 'token_email' => $payload['email']]);
                    return $this->respond_error('unauthorized', 'You do not have access to this download.', 403);
                }
            }

            // If OTP required, verify that a valid OTP verification transient exists for this token and client
            if (!empty($payload['require_otp'])) {
                $otpVerifiedKey = 'ap_download_otp_verified_' . $token;
                if (!get_transient($otpVerifiedKey)) {
                    return $this->respond_error('otp_required', 'An OTP is required to download this file. Request an OTP and verify it first.', 403);
                }
            }

            // All checks passed — stream ZIP
            $result = ZipStreamService::streamByToken($token, $payload['email'] ?? null, $payload['project_id'] ?? null);

            if (!is_array($result) || empty($result['success'])) {
                Logger::log(
                    'warning',
                    'download',
                    'Download failed or token invalid during streaming',
                    [
                        'token'  => $token,
                        'reason' => $result['message'] ?? ($result['reason'] ?? 'unknown'),
                    ]
                );

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
     * Request an OTP for a download token. Sends OTP to the token-bound email.
     */
    public function request_download_otp(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            if (empty($token)) {
                return $this->respond_error('missing_token', 'Token required.', 400);
            }

            $transientKey = 'ap_download_' . $token;
            $payload = get_transient($transientKey);
            if (empty($payload)) {
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ap_download_tokens WHERE token = %s LIMIT 1", $token), ARRAY_A);
                if ($row) {
                    $payload = [
                        'gallery_id' => (int) $row['gallery_id'],
                        'project_id' => (int) $row['project_id'],
                        'email' => $row['email'] ?? null,
                        'created_at' => strtotime($row['created_at']),
                        'expires_at' => !empty($row['expires_at']) ? strtotime($row['expires_at']) : null,
                        'require_otp' => !empty($row['require_otp']) ? (bool)$row['require_otp'] : false,
                    ];
                }
            }

            if (empty($payload) || empty($payload['email'])) {
                return $this->respond_error('invalid_token', 'Token invalid or not bound to an email.', 400);
            }

            $email = $payload['email'];

            $otpResult = \AperturePro\Auth\OTPService::generateAndSend($email, 'download');
            if (empty($otpResult['success'])) {
                return $this->respond_error('otp_failed', $otpResult['message'] ?? 'Failed to generate OTP.', 500);
            }

            // Store mapping from otp_key to token for verification flow (optional)
            $otpKey = $otpResult['otp_key'] ?? null;
            if ($otpKey) {
                set_transient('ap_download_otp_map_' . $otpKey, $token, \AperturePro\Auth\OTPService::TTL);
            }

            return $this->respond_success(['message' => 'OTP sent to your email.', 'otp_key' => $otpKey]);
        }, ['endpoint' => 'request_download_otp']);
    }

    /**
     * Verify OTP for a download token. On success, set a short-lived transient to allow download.
     */
    public function verify_download_otp(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $otpKey = sanitize_text_field($request->get_param('otp_key'));
            $code = sanitize_text_field($request->get_param('code'));

            if (empty($otpKey) || empty($code)) {
                return $this->respond_error('invalid_input', 'otp_key and code are required.', 400);
            }

            // Verify OTP
            $ok = \AperturePro\Auth\OTPService::verifyOtp($otpKey, $code);
            if (!$ok) {
                return $this->respond_error('invalid_otp', 'OTP invalid or expired.', 400);
            }

            // Map otp_key to token
            $token = get_transient('ap_download_otp_map_' . $otpKey);
            if (empty($token)) {
                return $this->respond_error('invalid_otp', 'OTP mapping not found.', 400);
            }

            // Mark token as OTP-verified for a short window
            set_transient('ap_download_otp_verified_' . $token, 1, 300); // 5 minutes

            // Remove mapping
            delete_transient('ap_download_otp_map_' . $otpKey);

            return $this->respond_success(['message' => 'OTP verified. You may now download the files.']);
        }, ['endpoint' => 'verify_download_otp']);
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

            // Determine client email if available via clients table
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
                    'require_otp' => 1, // require OTP by default for regenerated tokens
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%d']
            );

            if ($inserted === false) {
                Logger::log('error', 'download', 'Failed to persist download token', ['project_id' => $projectId, 'notify_admin' => true]);
                return $this->respond_error('persist_failed', 'Could not create download token.', 500);
            }

            // Also set transient for quick lookup
            $transientKey = 'ap_download_' . $token;
            $payload = [
                'gallery_id' => $galleryId,
                'project_id' => $projectId,
                'email'      => $email,
                'created_at' => time(),
                'expires_at' => strtotime($expiresAt),
                'require_otp' => true,
            ];
            set_transient($transientKey, $payload, 7 * 24 * 3600);

            $downloadUrl = add_query_arg('ap_download', $token, home_url('/'));

            Logger::log('info', 'download', 'Download token regenerated', ['project_id' => $projectId, 'token' => $token]);

            return $this->respond_success([
                'download_url' => $downloadUrl,
                'expires_at'   => $expiresAt,
                'otp_required' => true,
            ]);
        }, ['endpoint' => 'regenerate_download_token']);
    }

    protected function get_final_gallery_id_for_project(int $projectId): ?int
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'final'));
        return $id ? (int)$id : null;
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

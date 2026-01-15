<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Download\ZipStreamService;
use AperturePro\Helpers\Logger;
use AperturePro\Auth\MagicLinkService;
use AperturePro\Auth\CookieService;

/**
 * DownloadController
 *
 * - Validates download tokens bound to project and email.
 * - Provides endpoint for ZIP downloads (token in URL).
 * - Provides endpoint for regenerating a download token from the client portal (client must be authenticated via cookie).
 */
class DownloadController extends BaseController
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/download/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'download_zip'],
            'permission_callback' => '__return_true',
        ]);

        // Client portal: regenerate download token for project (valid for 7 days)
        register_rest_route($this->namespace, '/projects/(?P<project_id>\d+)/regenerate-download-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'regenerate_download_token'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Stream ZIP by token. Token must be bound to project/email as enforced by ZipStreamService.
     */
    public function download_zip(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));
            if (empty($token)) {
                return $this->respond_error('missing_token', 'Download token is required.', 400);
            }

            // If token is persisted in DB, it may include email binding. We do not require additional params here.
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
     * Regenerate a download token for a project from the client portal.
     *
     * Requirements:
     *  - Client must have a valid cookie session (CookieService)
     *  - Session project_id must match requested project_id
     *  - Generates a new token valid for 7 days and persists it as a transient and DB record
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

            // Create a new token bound to project and client email (if available)
            // We will persist token in DB ap_download_tokens for audit and regeneration
            global $wpdb;
            $downloadsTable = $wpdb->prefix . 'ap_download_tokens';

            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600)); // 7 days

            // Determine client email if available via clients table
            $clientsTable = $wpdb->prefix . 'ap_clients';
            $clientRow = $wpdb->get_row($wpdb->prepare("SELECT email FROM {$clientsTable} WHERE id = %d LIMIT 1", (int)$session['client_id']));
            $email = $clientRow ? $clientRow->email : null;

            $inserted = $wpdb->insert(
                $downloadsTable,
                [
                    'gallery_id' => self::get_final_gallery_id_for_project($projectId),
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                    'created_at' => current_time('mysql'),
                    'email'      => $email,
                    'project_id' => $projectId,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d']
            );

            if ($inserted === false) {
                Logger::log('error', 'download', 'Failed to persist download token', ['project_id' => $projectId, 'notify_admin' => true]);
                return $this->respond_error('persist_failed', 'Could not create download token.', 500);
            }

            // Also set transient for quick lookup
            $transientKey = 'ap_download_' . $token;
            $payload = [
                'gallery_id' => (int) self::get_final_gallery_id_for_project($projectId),
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
     * Helper: find final gallery id for a project (first final gallery)
     */
    protected static function get_final_gallery_id_for_project(int $projectId): ?int
    {
        global $wpdb;
        $galleries = $wpdb->prefix . 'ap_galleries';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$galleries} WHERE project_id = %d AND type = %s LIMIT 1", $projectId, 'final'));
        return $id ? (int)$id : null;
    }
}

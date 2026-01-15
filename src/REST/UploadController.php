<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Upload\ChunkedUploadHandler;
use AperturePro\Helpers\Logger;
use AperturePro\Email\EmailService;

/**
 * UploadController
 *
 * Exposes REST endpoints for chunked, resumable uploads:
 *  - POST /aperture/v1/uploads/start
 *  - POST /aperture/v1/uploads/{upload_id}/chunk
 *  - GET  /aperture/v1/uploads/{upload_id}/progress
 *
 * Security:
 *  - Endpoints accept either a valid WP nonce (X-WP-Nonce) or a user with 'upload_files' capability.
 *  - All inputs are sanitized.
 *
 * Behavior:
 *  - Accepts multipart/form-data chunk uploads (file field 'chunk') or raw body stream.
 *  - Writes chunks to disk using streaming to avoid memory blowouts.
 *  - Returns progress and status in JSON.
 */
class UploadController extends BaseController
{
    protected string $namespace = 'aperture/v1';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/uploads/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_session'],
            'permission_callback' => [$this, 'require_upload_permission'],
            'args'                => [
                'project_id' => ['required' => true],
                'uploader_id' => ['required' => false],
                'meta' => ['required' => false],
            ],
        ]);

        register_rest_route($this->namespace, '/uploads/(?P<upload_id>[a-f0-9]{32})/chunk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'upload_chunk'],
            'permission_callback' => [$this, 'require_upload_permission'],
            'args' => [
                'upload_id' => ['required' => true],
                'chunk_index' => ['required' => true],
                'total_chunks' => ['required' => true],
            ],
        ]);

        register_rest_route($this->namespace, '/uploads/(?P<upload_id>[a-f0-9]{32})/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_progress'],
            'permission_callback' => [$this, 'require_upload_permission'],
            'args' => [
                'upload_id' => ['required' => true],
            ],
        ]);
    }

    /**
     * Permission callback for upload endpoints.
     *
     * Accepts either:
     *  - A valid WP nonce in X-WP-Nonce header (action 'aperture_pro'), or
     *  - A logged-in user with 'upload_files' capability.
     *
     * This allows both authenticated users (photographers/admins) and client-side
     * code that has a valid nonce to upload.
     */
    public function require_upload_permission(): bool
    {
        // 1) If current user has upload capability, allow
        if (is_user_logged_in() && current_user_can('upload_files')) {
            return true;
        }

        // 2) Check WP nonce from header X-WP-Nonce
        $headers = getallheaders();
        $nonce = $headers['X-WP-Nonce'] ?? $headers['x-wp-nonce'] ?? null;
        if ($nonce && function_exists('wp_verify_nonce')) {
            // Use action 'aperture_pro' for upload nonces
            $ok = wp_verify_nonce($nonce, 'aperture_pro');
            if ($ok === 1 || $ok === 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new upload session.
     *
     * Expected POST body (JSON or form):
     *  - project_id (int) required
     *  - uploader_id (int) optional
     *  - meta (array) optional: original_filename, expected_size, mime_type, total_chunks
     */
    public function create_session(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $projectId = (int) $request->get_param('project_id');
            $uploaderId = $request->get_param('uploader_id') ? (int) $request->get_param('uploader_id') : null;
            $meta = $request->get_param('meta') ?? [];

            if ($projectId <= 0) {
                return $this->respond_error('invalid_input', 'project_id is required.', 400);
            }

            $sessionMeta = [
                'original_filename' => sanitize_file_name($meta['original_filename'] ?? ''),
                'expected_size' => isset($meta['expected_size']) ? (int)$meta['expected_size'] : null,
                'mime_type' => $meta['mime_type'] ?? null,
                'storage_key' => $meta['storage_key'] ?? null,
                'total_chunks' => isset($meta['total_chunks']) ? (int)$meta['total_chunks'] : null,
            ];

            $result = ChunkedUploadHandler::createSession($projectId, $uploaderId, $sessionMeta);

            if (empty($result['success'])) {
                return $this->respond_error('session_create_failed', $result['message'] ?? 'Failed to create upload session.', 500);
            }

            return $this->respond_success([
                'upload_id' => $result['upload_id'],
                'expires_at' => date('c', $result['expires_at']),
            ]);
        }, ['endpoint' => 'upload_create_session']);
    }

    /**
     * Accept a chunk for an upload session.
     *
     * Accepts either:
     *  - multipart/form-data with file field 'chunk' (recommended), or
     *  - raw body stream (php://input) with header X-Chunk-Size or Content-Length.
     *
     * Required params:
     *  - upload_id (path)
     *  - chunk_index (param)
     *  - total_chunks (param)
     */
    public function upload_chunk(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $uploadId = sanitize_text_field($request->get_param('upload_id'));
            $chunkIndex = (int) $request->get_param('chunk_index');
            $totalChunks = (int) $request->get_param('total_chunks');

            if (empty($uploadId) || $chunkIndex < 0 || $totalChunks <= 0) {
                return $this->respond_error('invalid_input', 'upload_id, chunk_index and total_chunks are required.', 400);
            }

            // Determine chunk source: prefer $_FILES['chunk']
            $chunkSource = null;
            if (!empty($_FILES['chunk']) && is_uploaded_file($_FILES['chunk']['tmp_name'])) {
                // Use the tmp file path
                $chunkSource = $_FILES['chunk']['tmp_name'];
            } else {
                // Use php://input stream
                $input = fopen('php://input', 'rb');
                if ($input === false) {
                    return $this->respond_error('no_chunk', 'No chunk data provided.', 400);
                }
                $chunkSource = $input;
            }

            // Call handler to accept chunk
            $result = ChunkedUploadHandler::acceptChunk($uploadId, $chunkIndex, $totalChunks, $chunkSource);

            // If we used php://input resource, ensure it's closed
            if (is_resource($chunkSource)) {
                @fclose($chunkSource);
            }

            if (empty($result['success'])) {
                // Log and surface to Health Card if critical
                Logger::log('warning', 'upload', 'Chunk upload failed', ['upload_id' => $uploadId, 'chunk' => $chunkIndex, 'message' => $result['message'] ?? null]);
                return $this->respond_error('chunk_failed', $result['message'] ?? 'Chunk upload failed.', 500);
            }

            return $this->respond_success([
                'message' => $result['message'] ?? 'Chunk accepted.',
                'progress' => $result['progress'] ?? null,
            ]);
        }, ['endpoint' => 'upload_chunk']);
    }

    /**
     * Get progress for an upload session.
     *
     * Returns progress percentage, received chunk count, and total chunks.
     */
    public function get_progress(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $uploadId = sanitize_text_field($request->get_param('upload_id'));
            if (empty($uploadId)) {
                return $this->respond_error('invalid_input', 'upload_id is required.', 400);
            }

            $result = ChunkedUploadHandler::getProgress($uploadId);
            if (empty($result['success'])) {
                return $this->respond_error('not_found', $result['message'] ?? 'Session not found.', 404);
            }

            return $this->respond_success([
                'progress' => $result['progress'],
                'received' => $result['received'],
                'total' => $result['total'],
            ]);
        }, ['endpoint' => 'upload_progress']);
    }
}

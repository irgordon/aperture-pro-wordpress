<?php

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use AperturePro\Storage\StorageFactory;
use AperturePro\Proof\ProofService;
use AperturePro\Helpers\Logger;
use AperturePro\Auth\CookieService;
use AperturePro\Config\Config;
use AperturePro\Repositories\ProjectRepository;

/**
 * ClientProofController
 *
 * Handles client-facing proof gallery endpoints:
 *  - List proofs for a project
 *  - Select images
 *  - Comment on images
 *  - Approve proofs
 *
 * PERFORMANCE NOTE:
 *  - We now instantiate the storage driver ONCE per request and pass it into
 *    ProofService::getProofUrlForImage() to avoid repeated StorageFactory::create()
 *    calls, which are relatively expensive due to config decryption.
 */
class ClientProofController extends BaseController
{
    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route('aperture/v1', '/projects/(?P<project_id>\d+)/proofs', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_proofs'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);

        register_rest_route('aperture/v1', '/proofs/(?P<gallery_id>\d+)/select', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'select_image'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);

        register_rest_route('aperture/v1', '/proofs/(?P<gallery_id>\d+)/select-batch', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'select_batch'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);

        register_rest_route('aperture/v1', '/proofs/(?P<gallery_id>\d+)/comment', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'comment_image'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);

        register_rest_route('aperture/v1', '/proofs/(?P<gallery_id>\d+)/approve', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'approve_proofs'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);

        register_rest_route('aperture/v1', '/client/log', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'client_log'],
                'permission_callback' => [$this, 'check_client_access'],
            ],
        ]);
    }

    /**
     * Permission callback for client access.
     *
     * SECURITY:
     *  - This should validate the client session/token and ensure the project
     *    belongs to the authenticated client.
     */
    public function check_client_access(WP_REST_Request $request)
    {
        $session = CookieService::getClientSession();

        if (!$session) {
            return new WP_Error('unauthorized', 'Client session not found', ['status' => 401]);
        }

        $session_project_id = (int)$session['project_id'];

        // If route has project_id
        $project_id = (int) $request->get_param('project_id');
        if ($project_id > 0 && $session_project_id !== $project_id) {
             return new WP_Error('forbidden', 'Access denied to this project', ['status' => 403]);
        }

        // If route has gallery_id
        $gallery_id = (int) $request->get_param('gallery_id');
        if ($gallery_id > 0) {
             $gallery_project_id = $this->get_project_id_for_gallery($gallery_id);
             if (!$gallery_project_id || $session_project_id !== $gallery_project_id) {
                 return new WP_Error('forbidden', 'Access denied to this gallery', ['status' => 403]);
             }
        }

        return true;
    }

    /**
     * List proof images for a project.
     *
     * PERFORMANCE CHANGE:
     *  - Instantiate StorageInterface ONCE via StorageFactory::create()
     *    and pass it into ProofService::getProofUrlForImage().
     */
    public function list_proofs(WP_REST_Request $request)
    {
        $project_id = (int) $request['project_id'];

        if ($project_id <= 0) {
            return new WP_Error('invalid_project', 'Invalid project ID', ['status' => 400]);
        }

        // Fetch project + images from your data layer (placeholder).
        $images = $this->get_project_images($project_id);

        // Pre-instantiate storage for this request.
        $storage = StorageFactory::create(); // Uses configured driver; includes decryption.

        // Batch generate proof URLs to avoid N+1 storage existence checks.
        try {
            $proofUrls = ProofService::getProofUrls($images, $storage);
        } catch (\Throwable $e) {
            Logger::log('error', 'client_proofs', 'Batch proof generation failed', [
                'project_id' => $project_id,
                'error'      => $e->getMessage(),
            ]);
            $proofUrls = [];
        }

        $proofs = [];
        foreach ($images as $key => $image) {
            $proofUrl = $proofUrls[$key] ?? null;

            if ($proofUrl) {
                $proofs[] = [
                    'id'          => (int) $image['id'],
                    'filename'    => $image['filename'],
                    'proof_url'   => $proofUrl,
                    'is_selected' => (bool) ($image['is_selected'] ?? false),
                    'comments'    => $image['comments'] ?? [],
                ];
            } else {
                Logger::log('error', 'client_proofs', 'Failed to generate proof URL', [
                    'project_id' => $project_id,
                    'image_id'   => $image['id'] ?? null,
                ]);
            }
        }

        return new WP_REST_Response([
            'project_id' => $project_id,
            'proofs'     => $proofs,
        ], 200);
    }

    /**
     * Select an image as approved/liked by the client.
     */
    public function select_image(WP_REST_Request $request)
    {
        $gallery_id = (int) $request['gallery_id'];
        $image_id   = (int) $request->get_param('image_id');
        $selected   = (bool) $request->get_param('selected');

        if ($gallery_id <= 0 || $image_id <= 0) {
            return new WP_Error('invalid_params', 'Invalid gallery or image ID', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_images';

        $result = $wpdb->update(
            $table,
            [
                'is_selected' => $selected ? 1 : 0,
                'updated_at'  => current_time('mysql', 1),
            ],
            [
                'id'         => $image_id,
                'gallery_id' => $gallery_id,
            ]
        );

        if ($result === false) {
            Logger::log('error', 'select_image', 'Failed to update image selection in DB', [
                'gallery_id' => $gallery_id,
                'image_id'   => $image_id,
                'error_code' => $wpdb->last_error,
            ]);

            return new WP_Error('db_error', 'Could not update selection', ['status' => 500]);
        }

        return new WP_REST_Response([
            'gallery_id' => $gallery_id,
            'image_id'   => $image_id,
            'selected'   => $selected,
        ], 200);
    }

    /**
     * Batch select images.
     */
    public function select_batch(WP_REST_Request $request)
    {
        $gallery_id = (int) $request['gallery_id'];
        $selections = $request->get_param('selections');

        if ($gallery_id <= 0 || !is_array($selections)) {
            return new WP_Error('invalid_params', 'Invalid gallery ID or selections format', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_images';

        $updated_count = 0;
        $errors = [];

        // Start transaction for consistency
        $wpdb->query('START TRANSACTION');

        foreach ($selections as $item) {
            $image_id = (int) ($item['image_id'] ?? 0);
            $selected = isset($item['selected']) && $item['selected'] ? 1 : 0;

            if ($image_id <= 0) {
                continue;
            }

            $result = $wpdb->update(
                $table,
                [
                    'is_selected' => $selected,
                    'updated_at'  => current_time('mysql', 1),
                ],
                [
                    'id'         => $image_id,
                    'gallery_id' => $gallery_id,
                ]
            );

            if ($result === false) {
                $errors[] = $image_id;
            } else {
                $updated_count++;
            }
        }

        // Commit regardless of partial failures to save what we can
        $wpdb->query('COMMIT');

        if (!empty($errors)) {
            Logger::log('warning', 'select_batch', 'Some selections failed to update', [
                'gallery_id' => $gallery_id,
                'failed_ids' => $errors,
                'db_error'   => $wpdb->last_error,
            ]);
        }

        return new WP_REST_Response([
            'gallery_id' => $gallery_id,
            'updated'    => $updated_count,
            'failed'     => count($errors),
            'failed_ids' => $errors
        ], 200);
    }

    /**
     * Add a comment to an image.
     */
    public function comment_image(WP_REST_Request $request)
    {
        $gallery_id = (int) $request['gallery_id'];
        $image_id   = (int) $request->get_param('image_id');
        $comment    = (string) $request->get_param('comment');

        if ($gallery_id <= 0 || $image_id <= 0 || $comment === '') {
            return new WP_Error('invalid_params', 'Missing required fields', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_images';

        // 1. Fetch existing comments
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT client_comments FROM $table WHERE id = %d AND gallery_id = %d",
            $image_id,
            $gallery_id
        ));

        if (!$row) {
            return new WP_Error('not_found', 'Image not found', ['status' => 404]);
        }

        $comments = json_decode($row->client_comments, true);
        if (!is_array($comments)) {
            $comments = [];
        }

        // 2. Append new comment
        $comments[] = [
            'author'    => 'Client',
            'text'      => sanitize_text_field($comment),
            'timestamp' => current_time('mysql', 1),
        ];

        // 3. Update DB
        $result = $wpdb->update(
            $table,
            [
                'client_comments' => json_encode($comments),
                'updated_at'      => current_time('mysql', 1),
            ],
            [
                'id'         => $image_id,
                'gallery_id' => $gallery_id,
            ]
        );

        if ($result === false) {
            Logger::log('error', 'comment_image', 'Failed to update image comments in DB', [
                'gallery_id' => $gallery_id,
                'image_id'   => $image_id,
                'error_code' => $wpdb->last_error,
            ]);

            return new WP_Error('db_error', 'Could not save comment', ['status' => 500]);
        }

        return new WP_REST_Response([
            'gallery_id' => $gallery_id,
            'image_id'   => $image_id,
            'comment'    => $comment,
            'comments'   => $comments,
        ], 200);
    }

    /**
     * Approve proofs for a gallery.
     */
    public function approve_proofs(WP_REST_Request $request)
    {
        $gallery_id = (int) $request['gallery_id'];

        if ($gallery_id <= 0) {
            return new WP_Error('invalid_gallery', 'Invalid gallery ID', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ap_galleries';

        $result = $wpdb->update(
            $table,
            [
                'status'     => 'approved',
                'updated_at' => current_time('mysql', 1),
            ],
            [
                'id' => $gallery_id
            ]
        );

        if ($result === false) {
            Logger::log('error', 'approve_proofs', 'Failed to update gallery status in DB', [
                'gallery_id' => $gallery_id,
                'error_code' => $wpdb->last_error,
            ]);

            return new WP_Error('db_error', 'Could not update gallery status', ['status' => 500]);
        }

        return new WP_REST_Response([
            'gallery_id' => $gallery_id,
            'status'     => 'approved',
        ], 200);
    }

    /**
     * Placeholder: fetch project images.
     *
     * SECURITY:
     *  - Ensure this only returns images belonging to the authenticated client.
     */
    protected function get_project_images(int $project_id): array
    {
        $repository = new ProjectRepository();
        return $repository->get_images_for_project($project_id);
    }

    protected function get_project_id_for_gallery(int $gallery_id): ?int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_galleries';
        $pid = $wpdb->get_var($wpdb->prepare("SELECT project_id FROM $table WHERE id = %d", $gallery_id));
        return $pid ? (int)$pid : null;
    }

    /**
     * Handle client-side log reports.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function client_log(WP_REST_Request $request)
    {
        // Enforce opt-in at the server level
        if (!Config::get('client_portal.enable_logging', false)) {
             return $this->respond_error('logging_disabled', 'Client logging is disabled.', 403);
        }

        $level = sanitize_text_field($request->get_param('level') ?? 'info');
        $context = sanitize_text_field($request->get_param('context') ?? 'client');
        $message = sanitize_text_field($request->get_param('message') ?? '');
        $meta = $request->get_param('meta');

        if (!is_array($meta)) {
            $meta = [];
        }

        // Validate level
        $allowedLevels = ['debug', 'info', 'warning', 'error', 'critical'];
        // Fix: JS often sends 'warn', normalize to 'warning'
        if ($level === 'warn') {
            $level = 'warning';
        }
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        // Add client session info to meta for correlation
        $session = CookieService::getClientSession();
        $clientId = $session['client_id'] ?? 0;
        $projectId = $session['project_id'] ?? 0;

        if ($clientId) {
            // Server-side Rate Limiting (per client session)
            // Use time-windowed key for accurate 1-minute limits
            $window = floor(time() / 60);
            $rateKey = 'ap_log_limit_' . $clientId . '_' . $window;
            $currentCount = (int) get_transient($rateKey);
            $limit = 60; // 60 logs per minute

            if ($currentCount >= $limit) {
                return $this->respond_error('rate_limit_exceeded', 'Too many log requests.', 429);
            }

            // Increment counter
            set_transient($rateKey, $currentCount + 1, 120); // 2 min TTL to allow window to expire naturally
        }

        if ($session) {
            $meta['client_id'] = $clientId;
            $meta['project_id'] = $projectId;
        }

        // Truncate message to avoid huge payloads
        if (strlen($message) > 1024) {
             $message = substr($message, 0, 1024) . '... (truncated)';
        }

        // Log to database
        Logger::log($level, $context, $message, $meta);

        return $this->respond_success(['logged' => true]);
    }
}

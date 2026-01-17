<?php

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use AperturePro\Storage\StorageFactory;
use AperturePro\Proof\ProofService;
use AperturePro\Helpers\Logger;
use AperturePro\Auth\CookieService;

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

        $proofs = [];
        foreach ($images as $image) {
            try {
                $proofUrl = ProofService::getProofUrlForImage($image, $storage);

                $proofs[] = [
                    'id'          => (int) $image['id'],
                    'filename'    => $image['filename'],
                    'proof_url'   => $proofUrl,
                    'is_selected' => (bool) ($image['is_selected'] ?? false),
                    'comments'    => $image['comments'] ?? [],
                ];
            } catch (\Throwable $e) {
                // Fail-soft: log and skip this image rather than failing the entire response.
                Logger::log('error', 'client_proofs', 'Failed to generate proof URL', [
                    'project_id' => $project_id,
                    'image_id'   => $image['id'] ?? null,
                    'error'      => $e->getMessage(),
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
        // Replace with real data access.
        return [
            [
                'id'          => 1,
                'filename'    => 'image-1.jpg',
                'path'        => 'projects/' . $project_id . '/image-1.jpg',
                'is_selected' => false,
                'comments'    => [],
            ],
            [
                'id'          => 2,
                'filename'    => 'image-2.jpg',
                'path'        => 'projects/' . $project_id . '/image-2.jpg',
                'is_selected' => true,
                'comments'    => [
                    ['author' => 'Client', 'text' => 'Love this one!'],
                ],
            ],
        ];
    }

    protected function get_project_id_for_gallery(int $gallery_id): ?int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ap_galleries';
        $pid = $wpdb->get_var($wpdb->prepare("SELECT project_id FROM $table WHERE id = %d", $gallery_id));
        return $pid ? (int)$pid : null;
    }
}

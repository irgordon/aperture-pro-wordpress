<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Auth\CookieService;
use AperturePro\Storage\StorageFactory;
use AperturePro\Workflow\Workflow;
use AperturePro\Helpers\Logger;

/**
 * ClientProofController
 *
 * Proofing endpoints used by client portal: list proofs, select, comment, approve.
 * All endpoints are guarded by client session checks and use with_error_boundary.
 */
class ClientProofController extends BaseController
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/projects/(?P<project_id>\d+)/proofs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_proofs'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/proofs/(?P<gallery_id>\d+)/select', [
            'methods'             => 'POST',
            'callback'            => [$this, 'select_image'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/proofs/(?P<gallery_id>\d+)/comment', [
            'methods'             => 'POST',
            'callback'            => [$this, 'comment_image'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/proofs/(?P<gallery_id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'approve_proofs'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Ensure the client session matches the project.
     */
    protected function require_client_session_for_project(int $projectId): ?array
    {
        $session = CookieService::getClientSession();

        if (!$session) {
            return null;
        }

        if ((int) $session['project_id'] !== $projectId) {
            return null;
        }

        return $session;
    }

    protected function get_gallery_by_id(int $galleryId): ?object
    {
        global $wpdb;
        $galleriesTable = $wpdb->prefix . 'ap_galleries';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$galleriesTable} WHERE id = %d LIMIT 1",
                $galleryId
            )
        );

        return $row ?: null;
    }

    /**
     * Return proofs for a project (proof gallery).
     */
    public function get_proofs(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $projectId = (int) $request['project_id'];

            $session = $this->require_client_session_for_project($projectId);
            if (!$session) {
                return $this->respond_error('unauthorized', 'You do not have access to this project.', 403);
            }

            global $wpdb;
            $galleriesTable = $wpdb->prefix . 'ap_galleries';
            $imagesTable    = $wpdb->prefix . 'ap_images';

            $gallery = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$galleriesTable} WHERE project_id = %d AND type = %s LIMIT 1",
                    $projectId,
                    'proof'
                )
            );

            if (!$gallery) {
                return $this->respond_error('not_found', 'Proof gallery not found.', 404);
            }

            $images = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$imagesTable} WHERE gallery_id = %d ORDER BY sort_order ASC, id ASC",
                    (int) $gallery->id
                )
            );

            $storage = StorageFactory::make();

            $imageData = [];
            foreach ($images as $img) {
                $comments = [];
                if (!empty($img->client_comments)) {
                    $decoded = json_decode($img->client_comments, true);
                    if (is_array($decoded)) {
                        $comments = $decoded;
                    }
                }

                $imageData[] = [
                    'id'          => (int) $img->id,
                    'url'         => $storage->getUrl($img->storage_key_original, ['signed' => true, 'expires' => 300]),
                    'is_selected' => (bool) $img->is_selected,
                    'comments'    => $comments,
                ];
            }

            return $this->respond_success([
                'gallery_id' => (int) $gallery->id,
                'status'     => (string) $gallery->status,
                'images'     => $imageData,
            ]);
        }, ['endpoint' => 'client_get_proofs']);
    }

    /**
     * Toggle selection for an image.
     */
    public function select_image(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $galleryId = (int) $request['gallery_id'];
            $imageId   = (int) $request->get_param('image_id');
            $selected  = (bool) $request->get_param('selected');

            if ($galleryId <= 0 || $imageId <= 0) {
                return $this->respond_error('invalid_input', 'Invalid gallery or image id.', 400);
            }

            $gallery = $this->get_gallery_by_id($galleryId);
            if (!$gallery) {
                return $this->respond_error('not_found', 'Gallery not found.', 404);
            }

            $session = $this->require_client_session_for_project((int) $gallery->project_id);
            if (!$session) {
                return $this->respond_error('unauthorized', 'You do not have access to this gallery.', 403);
            }

            if ((string) $gallery->status !== 'proofing') {
                return $this->respond_error('invalid_state', 'This gallery is not in proofing state.', 409);
            }

            global $wpdb;
            $imagesTable = $wpdb->prefix . 'ap_images';

            $updated = $wpdb->update(
                $imagesTable,
                [
                    'is_selected' => $selected ? 1 : 0,
                    'updated_at'  => current_time('mysql'),
                ],
                [
                    'id'        => $imageId,
                    'gallery_id'=> $galleryId,
                ],
                [
                    '%d',
                    '%s',
                ],
                [
                    '%d',
                    '%d',
                ]
            );

            if ($updated === false) {
                return $this->respond_error('update_failed', 'Could not update selection.', 500);
            }

            return $this->respond_success([
                'image_id'  => $imageId,
                'selected'  => $selected,
                'gallery_id'=> $galleryId,
            ]);
        }, ['endpoint' => 'client_select_image']);
    }

    /**
     * Add a comment to an image.
     */
    public function comment_image(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $galleryId = (int) $request['gallery_id'];
            $imageId   = (int) $request->get_param('image_id');
            $comment   = sanitize_textarea_field((string) $request->get_param('comment'));

            if ($galleryId <= 0 || $imageId <= 0 || $comment === '') {
                return $this->respond_error('invalid_input', 'Invalid gallery, image, or comment.', 400);
            }

            $gallery = $this->get_gallery_by_id($galleryId);
            if (!$gallery) {
                return $this->respond_error('not_found', 'Gallery not found.', 404);
            }

            $session = $this->require_client_session_for_project((int) $gallery->project_id);
            if (!$session) {
                return $this->respond_error('unauthorized', 'You do not have access to this gallery.', 403);
            }

            if ((string) $gallery->status !== 'proofing') {
                return $this->respond_error('invalid_state', 'This gallery is not in proofing state.', 409);
            }

            global $wpdb;
            $imagesTable = $wpdb->prefix . 'ap_images';

            $imageRow = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT client_comments FROM {$imagesTable} WHERE id = %d AND gallery_id = %d LIMIT 1",
                    $imageId,
                    $galleryId
                )
            );

            if (!$imageRow) {
                return $this->respond_error('not_found', 'Image not found.', 404);
            }

            $comments = [];
            if (!empty($imageRow->client_comments)) {
                $decoded = json_decode($imageRow->client_comments, true);
                if (is_array($decoded)) {
                    $comments = $decoded;
                }
            }

            $comments[] = [
                'comment'    => $comment,
                'created_at' => current_time('mysql'),
            ];

            $updated = $wpdb->update(
                $imagesTable,
                [
                    'client_comments' => wp_json_encode($comments),
                    'updated_at'      => current_time('mysql'),
                ],
                [
                    'id'         => $imageId,
                    'gallery_id' => $galleryId,
                ],
                [
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                    '%d',
                ]
            );

            if ($updated === false) {
                return $this->respond_error('update_failed', 'Could not save comment.', 500);
            }

            return $this->respond_success([
                'image_id'  => $imageId,
                'gallery_id'=> $galleryId,
                'comments'  => $comments,
            ]);
        }, ['endpoint' => 'client_comment_image']);
    }

    /**
     * Approve proofs (finalize selection).
     */
    public function approve_proofs(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $galleryId = (int) $request['gallery_id'];
            if ($galleryId <= 0) {
                return $this->respond_error('invalid_input', 'Invalid gallery id.', 400);
            }

            $gallery = $this->get_gallery_by_id($galleryId);
            if (!$gallery) {
                return $this->respond_error('not_found', 'Gallery not found.', 404);
            }

            $session = $this->require_client_session_for_project((int) $gallery->project_id);
            if (!$session) {
                return $this->respond_error('unauthorized', 'You do not have access to this gallery.', 403);
            }

            if ((string) $gallery->status !== 'proofing') {
                return $this->respond_error('invalid_state', 'This gallery is not in proofing state.', 409);
            }

            global $wpdb;
            $galleriesTable = $wpdb->prefix . 'ap_galleries';

            $updated = $wpdb->update(
                $galleriesTable,
                [
                    'status'     => 'approved',
                    'updated_at' => current_time('mysql'),
                ],
                [
                    'id' => $galleryId,
                ],
                [
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                ]
            );

            if ($updated === false) {
                return $this->respond_error('update_failed', 'Could not approve proofs.', 500);
            }

            // Trigger workflow hook
            Workflow::onProofsApproved((int) $gallery->project_id, $galleryId);

            Logger::log('info', 'client_proof', 'Proofs approved by client', ['project_id' => (int)$gallery->project_id, 'gallery_id' => $galleryId]);

            return $this->respond_success([
                'project_id' => (int) $gallery->project_id,
                'gallery_id' => $galleryId,
                'status'     => 'approved',
            ]);
        }, ['endpoint' => 'client_approve_proofs']);
    }
}

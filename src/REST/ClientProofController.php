<?php

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use AperturePro\Storage\StorageFactory;
use AperturePro\Proof\ProofService;
use AperturePro\Helpers\Logger;

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
    public function register_routes()
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
        // Placeholder: implement your client auth/session logic here.
        // Return true on success, or WP_Error on failure.
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

        // TODO: persist selection in your data layer.
        // Example: $this->repository->setSelection($gallery_id, $image_id, $selected);

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

        // TODO: persist comment in your data layer.

        return new WP_REST_Response([
            'gallery_id' => $gallery_id,
            'image_id'   => $image_id,
            'comment'    => $comment,
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

        // TODO: mark gallery as approved in your data layer.

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
}

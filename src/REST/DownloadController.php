<?php

namespace AperturePro\REST;

use WP_REST_Request;
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
    }

    /**
     * Streams a ZIP for a valid download token.
     *
     * ZipStreamService::streamByToken is expected to handle:
     *  - token validation
     *  - header emission
     *  - streaming output
     *  - returning an array with ['success' => bool, 'message' => string, 'status' => int]
     *
     * If streaming succeeds, the service may have already emitted output and exited.
     * We still return a REST response for completeness, but in practice the stream
     * will be the primary response.
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

            // If ZipStreamService handled the output, this response may not be used.
            return $this->respond_success(['message' => 'Download started.']);
        }, ['endpoint' => 'download_zip']);
    }
}

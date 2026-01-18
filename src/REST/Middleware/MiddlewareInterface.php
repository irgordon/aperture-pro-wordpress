<?php
declare(strict_types=1);

namespace AperturePro\REST\Middleware;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

interface MiddlewareInterface
{
    /**
     * Return null to allow request, or WP_Error/WP_REST_Response to block.
     *
     * @return WP_Error|WP_REST_Response|null
     */
    public function handle(WP_REST_Request $request);
}

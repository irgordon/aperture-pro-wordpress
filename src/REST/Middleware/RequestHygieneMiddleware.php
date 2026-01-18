<?php
declare(strict_types=1);

namespace AperturePro\REST\Middleware;

use WP_REST_Request;
use WP_Error;

final class RequestHygieneMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $max_body_bytes = 100_000 // 100KB default
    ) {}

    public function handle(WP_REST_Request $request)
    {
        $body = (string) $request->get_body();

        if (strlen($body) > $this->max_body_bytes) {
            return new WP_Error('ap_payload_too_large', 'Request payload too large.', ['status' => 413]);
        }

        // Light-touch pattern detection (do not over-block).
        $s = strtolower($body . ' ' . wp_json_encode($request->get_params()));
        if (str_contains($s, 'union select') || str_contains($s, 'sleep(') || str_contains($s, 'benchmark(')) {
            return new WP_Error('ap_suspicious_request', 'Request rejected.', ['status' => 400]);
        }

        return null;
    }
}

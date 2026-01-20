<?php
declare(strict_types=1);

namespace AperturePro\REST\Middleware;

use AperturePro\Config\Config;
use AperturePro\Security\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $action,          // e.g. "otp_verify", "magic_link_request"
        private readonly int $limit,
        private readonly int $window_seconds,
        private readonly string $scope = 'ip'     // ip|ip+email|custom
    ) {}

    public function handle(WP_REST_Request $request)
    {
        $key = $this->build_key($request);

        $result = $this->limiter->attempt($key, $this->limit, $this->window_seconds);

        if ($result['allowed']) {
            // Optionally expose headers for client UX/debugging.
            if (Config::get('security.expose_rate_limit_headers')) {
                add_filter('rest_post_dispatch', function ($response) use ($result) {
                    if ($response instanceof WP_REST_Response) {
                        $response->header('X-RateLimit-Limit', (string) $result['limit']);
                        $response->header('X-RateLimit-Remaining', (string) $result['remaining']);
                        $response->header('X-RateLimit-Reset', (string) $result['reset_in']);
                    }
                    return $response;
                }, 10, 1);
            }

            return null;
        }

        return new WP_Error(
            'ap_rate_limited',
            'Too many attempts. Please wait a moment and try again.',
            [
                'status' => 429,
                'limit' => $result['limit'],
                'reset_in' => $result['reset_in'],
            ]
        );
    }

    private function build_key(WP_REST_Request $request): string
    {
        $ip = $this->get_client_ip();
        $route = (string) $request->get_route();
        $method = (string) $request->get_method();

        $email = '';
        if ($this->scope === 'ip+email') {
            $email = (string) ($request->get_param('email') ?? '');
            $email = strtolower(trim(sanitize_email($email)));
        }

        return implode('|', array_filter([
            'aperture',
            'rest',
            $this->action,
            $method,
            $route,
            'ip:' . $ip,
            $email ? 'email:' . $email : null,
        ]));
    }

    private function get_client_ip(): string
    {
        // Keep it conservative: do not trust X-Forwarded-For unless you explicitly control the proxy.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = is_string($ip) ? $ip : '';
        return $ip ?: '0.0.0.0';
    }
}

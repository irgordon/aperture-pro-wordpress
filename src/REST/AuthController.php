<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Auth\MagicLinkService;
use AperturePro\Auth\CookieService;
use AperturePro\Workflow\Workflow;

class AuthController extends BaseController
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/auth/magic-link/consume', [
            'methods'             => 'POST',
            'callback'            => [$this, 'consume_magic_link'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/auth/session', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_session'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function consume_magic_link(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));

            if (!$token) {
                return $this->respond_error('missing_token', 'Magic link token missing.');
            }

            $payload = MagicLinkService::consume($token);

            if (!$payload) {
                return $this->respond_error(
                    'invalid_token',
                    'This link is no longer valid.',
                    410
                );
            }

            CookieService::setClientSession(
                $payload['client_id'],
                $payload['project_id']
            );

            return $this->respond_success([
                'project_id' => $payload['project_id'],
                'client_id'  => $payload['client_id'],
            ]);
        }, ['endpoint' => 'auth_magic_link_consume']);
    }

    public function get_session(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () {
            $session = CookieService::getClientSession();

            if (!$session) {
                return $this->respond_error(
                    'no_session',
                    'No active session.',
                    401
                );
            }

            $status = Workflow::getProjectStatus(
                (int) $session['project_id']
            );

            return $this->respond_success([
                'client_id'  => $session['client_id'],
                'project_id' => $session['project_id'],
                'status'     => $status,
            ]);
        }, ['endpoint' => 'auth_session']);
    }
}

<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Auth\MagicLinkService;
use AperturePro\Auth\CookieService;
use AperturePro\Workflow\Workflow;
use AperturePro\Helpers\Logger;

/**
 * AuthController
 *
 * Handles magic link consumption and session retrieval.
 * Integrates with CookieService and Workflow for project status.
 */
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

    /**
     * Consume a magic link token and set a client cookie session.
     */
    public function consume_magic_link(WP_REST_Request $request)
    {
        return $this->with_error_boundary(function () use ($request) {
            $token = sanitize_text_field($request->get_param('token'));

            if (!$token) {
                return $this->respond_error('missing_token', 'Magic link token missing.', 400);
            }

            $payload = MagicLinkService::consume($token);

            if (!$payload) {
                return $this->respond_error(
                    'invalid_token',
                    'This link is no longer valid.',
                    410
                );
            }

            // payload expected to include client_id and project_id and optionally email
            if (empty($payload['client_id']) || empty($payload['project_id'])) {
                Logger::log('warning', 'auth', 'Magic link payload missing required fields', ['payload' => $payload]);
                return $this->respond_error('invalid_token_payload', 'Invalid magic link payload.', 400);
            }

            CookieService::setClientSession(
                (int) $payload['client_id'],
                (int) $payload['project_id']
            );

            Logger::log('info', 'auth', 'Magic link consumed and session created', ['client_id' => $payload['client_id'], 'project_id' => $payload['project_id']]);

            return $this->respond_success([
                'project_id' => (int) $payload['project_id'],
                'client_id'  => (int) $payload['client_id'],
                'email'      => $payload['email'] ?? null,
            ]);
        }, ['endpoint' => 'auth_magic_link_consume']);
    }

    /**
     * Return the current client session (if any) and project status.
     */
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

            $projectId = (int) $session['project_id'];

            $status = Workflow::getProjectStatus($projectId);

            return $this->respond_success([
                'client_id'  => (int) $session['client_id'],
                'project_id' => $projectId,
                'status'     => $status,
            ]);
        }, ['endpoint' => 'auth_session']);
    }
}

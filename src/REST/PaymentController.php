<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Services\PaymentService;
use AperturePro\Config\Config;
use AperturePro\Helpers\Logger;
use AperturePro\Helpers\Crypto;

/**
 * PaymentController
 *
 * Webhook receiver for payment provider events.
 */
class PaymentController extends BaseController
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/webhooks/payment', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // provider will authenticate via signature
        ]);

        register_rest_route($this->namespace, '/projects/(?P<id>\d+)/payment-summary', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payment_summary'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/projects/(?P<id>\d+)/payment-timeline', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payment_timeline'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/projects/(?P<id>\d+)/retry-payment', [
            'methods' => 'POST',
            'callback' => [$this, 'retry_payment'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
    }

    public function check_admin_permissions()
    {
        return current_user_can('manage_options');
    }

    public function get_payment_summary(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        $summary = PaymentService::getPaymentSummary($projectId);

        if (!$summary) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }

        return new \WP_REST_Response($summary, 200);
    }

    public function get_payment_timeline(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        $timeline = PaymentService::getPaymentTimeline($projectId);
        return new \WP_REST_Response($timeline, 200);
    }

    public function retry_payment(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        $intent = PaymentService::recreatePaymentIntent($projectId);

        return new \WP_REST_Response([
            'payment_intent' => $intent->id,
            'checkout_url'   => $intent->next_action->redirect_to_url->url ?? null,
        ], 200);
    }

    public function handle_webhook(WP_REST_Request $request)
    {
        // Read raw body
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_PAYMENT_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMENT_SIGNATURE'] ?? '';

        // Load config (may include encrypted webhook secret)
        $config = Config::all();
        $encryptedSecret = $config['payment']['webhook_secret'] ?? '';

        if (empty($encryptedSecret)) {
            Logger::log('error', 'payment', 'Webhook secret not configured', ['notify_admin' => true]);
            return new \WP_REST_Response(['success' => false, 'message' => 'Webhook secret not configured'], 500);
        }

        // Decrypt secret
        $secret = \AperturePro\Helpers\Crypto::decrypt($encryptedSecret);
        if ($secret === null) {
            Logger::log('error', 'payment', 'Failed to decrypt webhook secret', ['notify_admin' => true]);
            return new \WP_REST_Response(['success' => false, 'message' => 'Webhook secret invalid'], 500);
        }

        if (!PaymentService::verifySignature($payload, $signature, $secret)) {
            Logger::log('warning', 'payment', 'Webhook signature verification failed', ['signature' => $signature]);
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            Logger::log('warning', 'payment', 'Webhook payload invalid JSON', []);
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        $result = PaymentService::processEvent($event);
        if (!$result['success']) {
            return new \WP_REST_Response(['success' => false, 'message' => $result['message']], 500);
        }

        return new \WP_REST_Response(['success' => true, 'message' => $result['message']], 200);
    }
}

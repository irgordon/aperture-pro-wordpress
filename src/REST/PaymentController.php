<?php

namespace AperturePro\REST;

use WP_REST_Request;
use AperturePro\Services\PaymentService;
use AperturePro\Config\Config;
use AperturePro\Helpers\Logger;

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
    }

    public function handle_webhook(WP_REST_Request $request)
    {
        // Read raw body
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_PAYMENT_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMENT_SIGNATURE'] ?? '';

        $config = Config::all();
        $secret = $config['payment']['webhook_secret'] ?? '';

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

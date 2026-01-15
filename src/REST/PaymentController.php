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

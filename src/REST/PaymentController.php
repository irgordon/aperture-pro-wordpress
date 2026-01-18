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
        // Support dynamic provider in URL
        register_rest_route($this->namespace, '/webhooks/payment/(?P<provider>[a-z0-9_-]+)', [
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
        try {
            $intent = PaymentService::recreatePaymentIntent($projectId);

            return new \WP_REST_Response([
                'payment_intent' => $intent->id,
                'checkout_url'   => $intent->checkout_url,
            ], 200);
        } catch (\Exception $e) {
             return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public function handle_webhook(WP_REST_Request $request)
    {
        $provider = $request['provider'];
        $payload = $request->get_body();

        $flatHeaders = [];
        foreach ($request->get_headers() as $key => $values) {
            $flatHeaders[$key] = implode(', ', $values);
        }

        $result = PaymentService::handleWebhook($provider, $payload, $flatHeaders);

        if (!$result['success']) {
            return new \WP_REST_Response(['message' => $result['message']], 400);
        }

        return new \WP_REST_Response(['success' => true], 200);
    }
}

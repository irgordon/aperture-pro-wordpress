<?php
declare(strict_types=1);

namespace AperturePro\REST;

use WP_REST_Request;
use WP_REST_Response;
use AperturePro\Services\PaymentService;
use AperturePro\Payments\PaymentProviderFactory;
use AperturePro\Repositories\ProjectRepository;
use AperturePro\Services\WorkflowAdapter;
use AperturePro\Helpers\Logger;

/**
 * PaymentController
 *
 * Webhook receiver for payment provider events and admin payment management.
 */
class PaymentController extends BaseController
{
    protected PaymentService $payments;

    public function __construct()
    {
        // Manual DI since Loader doesn't support it yet
        $this->payments = new PaymentService(
            new ProjectRepository(),
            new WorkflowAdapter()
        );
    }

    public function register_routes(): void
    {
        // Support dynamic provider in URL (New Webhook)
        register_rest_route($this->namespace, '/webhooks/payment/(?P<provider>[a-zA-Z0-9_-]+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // provider will authenticate via signature
        ]);

        // Admin endpoints (Ported)
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

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $providerName = sanitize_text_field($request['provider']);

        try {
            $provider = PaymentProviderFactory::make($providerName);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['error' => 'Unknown provider'], 400);
        }

        $payload = $request->get_body();

        // Flatten headers and normalize keys for providers
        $flatHeaders = [];
        foreach ($request->get_headers() as $key => $values) {
            $value = is_array($values) ? implode(', ', $values) : $values;
            $flatHeaders[$key] = $value;

            // Normalize for case-sensitive SDKs
            if (strtolower($key) === 'stripe-signature') {
                $flatHeaders['Stripe-Signature'] = $value;
            }
        }

        try {
            $event = $provider->verify_webhook($payload, $flatHeaders);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['error' => 'Invalid signature: ' . $e->getMessage()], 400);
        }

        // Normalize provider event → PaymentUpdate DTO
        $update = $provider->handle_webhook_event($event);

        // Apply update to project
        $this->payments->apply_update($update);

        return new WP_REST_Response(['received' => true], 200);
    }

    public function get_payment_summary(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        $summary = $this->payments->getPaymentSummary($projectId);

        if (!$summary) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }

        return new \WP_REST_Response($summary, 200);
    }

    public function get_payment_timeline(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        $timeline = $this->payments->getPaymentTimeline($projectId);
        return new \WP_REST_Response($timeline, 200);
    }

    public function retry_payment(WP_REST_Request $request)
    {
        $projectId = (int) $request['id'];
        try {
            $intent = $this->payments->recreatePaymentIntent($projectId);

            return new \WP_REST_Response([
                'payment_intent' => $intent->id,
                'checkout_url'   => $intent->checkout_url ?? null,
            ], 200);
        } catch (\Exception $e) {
             return new \WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
}

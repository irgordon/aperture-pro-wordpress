<?php
namespace AperturePro\Payments\Providers;

use AperturePro\Payments\PaymentProviderInterface;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;

class StripeProvider implements PaymentProviderInterface
{
    public function get_name(): string { return 'stripe'; }

    public function create_payment_intent(array $args): PaymentIntentResult {
        // Stub: In a real app, this calls Stripe API
        $id = 'pi_' . bin2hex(random_bytes(10));
        return new PaymentIntentResult($id, null, $args);
    }

    public function get_checkout_url(PaymentIntentResult $intent): ?string {
        return null;
    }

    public function verify_webhook(string $payload, array $headers): WebhookEvent {
        // In a real implementation, we would verify headers['Stripe-Signature']
        // using the webhook secret.

        $data = json_decode($payload);
        if (!$data) {
             throw new \Exception("Invalid JSON payload");
        }
        $type = $data->type ?? 'unknown';
        return new WebhookEvent($type, $data);
    }

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate {
        $data = $event->payload->data->object ?? null;

        // If data is null, try to use the payload itself (fallback)
        if (!$data) {
            $data = $event->payload;
        }

        $projectId = $data->metadata->project_id ?? null;
        $status = 'unknown';
        $intentId = $data->id ?? '';
        $amount = null;
        $currency = null;

        switch ($event->type) {
            case 'payment_intent.succeeded':
            case 'charge.succeeded':
                $status = 'paid';
                // Handle different structures for payment intent vs charge
                $amountRaw = $data->amount_received ?? $data->amount ?? 0;
                $amount = $amountRaw / 100;
                $currency = strtoupper($data->currency ?? 'USD');
                // Fallback for intent ID
                $intentId = $data->id ?? ($data->payment_intent ?? '');
                break;

            case 'payment_intent.payment_failed':
                $status = 'failed';
                $intentId = $data->id ?? '';
                break;

            case 'charge.refunded':
                $status = 'refunded';
                // For refunds, $data is a charge, usually has payment_intent
                $intentId = $data->payment_intent ?? $data->id;
                break;
        }

        return new PaymentUpdate(
            (int)$projectId,
            $status,
            $intentId,
            $amount,
            $currency,
            $event->payload
        );
    }

    public function refund(string $payment_intent_id, int $amount = null): RefundResult {
        // Stub
        return new RefundResult(true, 're_' . bin2hex(random_bytes(10)));
    }
}

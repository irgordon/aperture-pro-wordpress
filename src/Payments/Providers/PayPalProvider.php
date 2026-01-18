<?php
namespace AperturePro\Payments\Providers;

use AperturePro\Payments\PaymentProviderInterface;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;

class PayPalProvider implements PaymentProviderInterface
{
    public function get_name(): string { return 'paypal'; }

    public function create_payment_intent(array $args): PaymentIntentResult {
        return new PaymentIntentResult('PAY-dummy-id');
    }

    public function get_checkout_url(PaymentIntentResult $intent): ?string {
        return 'https://paypal.com/checkout?token=' . $intent->id;
    }

    public function verify_webhook(string $payload, array $headers): WebhookEvent {
        return new WebhookEvent('unknown', json_decode($payload));
    }

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate {
        return new PaymentUpdate(null, 'unknown', '', 0.0, 'USD', $event->payload);
    }

    public function refund(string $payment_intent_id, int $amount = null): RefundResult {
        return new RefundResult(true, 'refund-id');
    }
}

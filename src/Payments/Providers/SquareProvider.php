<?php
namespace AperturePro\Payments\Providers;

use AperturePro\Payments\PaymentProviderInterface;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;

class SquareProvider implements PaymentProviderInterface
{
    public function get_name(): string { return 'square'; }

    public function create_payment_intent(array $args): PaymentIntentResult {
        return new PaymentIntentResult(
            id: 'sq-dummy-id',
            amount: $args['amount'] ?? 0,
            currency: 'USD',
            client_secret: null,
            provider: 'square'
        );
    }

    public function get_checkout_url(PaymentIntentResult $intent): ?string {
        return null;
    }

    public function verify_webhook(string $payload, array $headers): WebhookEvent {
        return new WebhookEvent('square', 'unknown', json_decode($payload));
    }

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate {
        return new PaymentUpdate(null, 'unknown', '', 0.0, 'USD', $event->payload);
    }

    public function refund(string $payment_intent_id, int $amount = null): RefundResult {
        return new RefundResult(
            refund_id: 're_dummy',
            payment_intent_id: $payment_intent_id,
            amount: (float)($amount ?? 0),
            currency: 'USD'
        );
    }
}

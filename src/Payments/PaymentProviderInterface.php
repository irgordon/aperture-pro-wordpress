<?php
namespace AperturePro\Payments;

use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;

interface PaymentProviderInterface
{
    public function get_name(): string;

    public function create_payment_intent(array $args): PaymentIntentResult;

    public function get_checkout_url(PaymentIntentResult $intent): ?string;

    public function verify_webhook(string $payload, array $headers): WebhookEvent;

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate;

    public function refund(string $payment_intent_id, int $amount = null): RefundResult;
}

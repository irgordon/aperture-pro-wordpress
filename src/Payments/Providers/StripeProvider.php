<?php
declare(strict_types=1);

namespace AperturePro\Payments\Providers;

use AperturePro\Payments\PaymentProviderInterface;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeProvider implements PaymentProviderInterface
{
    protected StripeClient $client;
    protected string $webhookSecret;

    public function __construct()
    {
        $settings = aperture_pro()->settings->get('stripe');
        $this->client = new StripeClient($settings['secret_key']);
        $this->webhookSecret = $settings['webhook_secret'];
    }

    public function get_name(): string
    {
        return 'stripe';
    }

    public function create_payment_intent(array $args): PaymentIntentResult
    {
        $intent = $this->client->paymentIntents->create([
            'amount'   => $args['amount'],
            'currency' => strtolower($args['currency']),
            'metadata' => $args['metadata'] ?? [],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return new PaymentIntentResult(
            id: $intent->id,
            amount: $intent->amount,
            currency: strtoupper($intent->currency),
            client_secret: $intent->client_secret,
            provider: 'stripe',
            raw: $intent->toArray()
        );
    }

    public function get_checkout_url(PaymentIntentResult $intent): ?string
    {
        // Stripe Payment Element does not use redirect URLs
        return null;
    }

    public function verify_webhook(string $payload, array $headers): WebhookEvent
    {
        $signature = $headers['Stripe-Signature'] ?? '';

        $event = Webhook::constructEvent(
            $payload,
            $signature,
            $this->webhookSecret
        );

        return new WebhookEvent(
            provider: 'stripe',
            type: $event->type,
            payload: $event->data->object->toArray()
        );
    }

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate
    {
        $object = $event->payload;

        $intentId = $object['id'] ?? ($object['payment_intent'] ?? null);
        $projectId = (int)($object['metadata']['project_id'] ?? 0);

        $status = match ($event->type) {
            'payment_intent.succeeded' => 'paid',
            'payment_intent.payment_failed' => 'failed',
            'charge.refunded' => 'refunded',
            default => 'unknown',
        };

        $amount = isset($object['amount_received'])
            ? $object['amount_received'] / 100
            : null;

        $currency = isset($object['currency'])
            ? strtoupper($object['currency'])
            : null;

        return new PaymentUpdate(
            project_id: $projectId,
            status: $status,
            payment_intent_id: $intentId,
            amount: $amount,
            currency: $currency,
            raw_event: $event->payload
        );
    }

    public function refund(string $payment_intent_id, ?int $amount = null): RefundResult
    {
        $refund = $this->client->refunds->create([
            'payment_intent' => $payment_intent_id,
            'amount' => $amount,
        ]);

        return new RefundResult(
            refund_id: $refund->id,
            payment_intent_id: $payment_intent_id,
            amount: $refund->amount / 100,
            currency: strtoupper($refund->currency),
            raw: $refund->toArray()
        );
    }
}

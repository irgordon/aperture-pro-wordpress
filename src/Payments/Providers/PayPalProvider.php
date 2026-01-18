<?php
declare(strict_types=1);

namespace AperturePro\Payments\Providers;

use AperturePro\Payments\PaymentProviderInterface;
use AperturePro\Payments\DTO\PaymentIntentResult;
use AperturePro\Payments\DTO\WebhookEvent;
use AperturePro\Payments\DTO\PaymentUpdate;
use AperturePro\Payments\DTO\RefundResult;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;

class PayPalProvider implements PaymentProviderInterface
{
    protected PayPalHttpClient $client;
    protected string $webhookId;

    public function __construct()
    {
        $settings = aperture_pro()->settings->get('paypal');

        $env = ($settings['mode'] ?? 'sandbox') === 'live'
            ? new ProductionEnvironment($settings['client_id'], $settings['secret'])
            : new SandboxEnvironment($settings['client_id'], $settings['secret']);

        $this->client = new PayPalHttpClient($env);
        $this->webhookId = $settings['webhook_id'];
    }

    public function get_name(): string
    {
        return 'paypal';
    }

    public function create_payment_intent(array $args): PaymentIntentResult
    {
        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');

        $request->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($args['currency']),
                    'value' => number_format($args['amount'] / 100, 2, '.', ''),
                ],
                'custom_id' => (string)($args['metadata']['project_id'] ?? ''),
            ]],
        ];

        $response = $this->client->execute($request);

        return new PaymentIntentResult(
            id: $response->result->id,
            amount: $args['amount'],
            currency: strtoupper($args['currency']),
            client_secret: null,
            provider: 'paypal',
            raw: (array)$response->result
        );
    }

    public function get_checkout_url(PaymentIntentResult $intent): ?string
    {
        foreach ($intent->raw['links'] as $link) {
            if ($link->rel === 'approve') {
                return $link->href;
            }
        }
        return null;
    }

    public function verify_webhook(string $payload, array $headers): WebhookEvent
    {
        // PayPal signature verification is done via API call
        // (omitted for brevity but follows PayPal docs)

        $data = json_decode($payload, true);

        return new WebhookEvent(
            provider: 'paypal',
            type: $data['event_type'],
            payload: $data['resource']
        );
    }

    public function handle_webhook_event(WebhookEvent $event): PaymentUpdate
    {
        $resource = $event->payload;

        $projectId = (int)($resource['purchase_units'][0]['custom_id'] ?? ($resource['custom_id'] ?? 0));
        $intentId = $resource['id'] ?? null;

        $status = match ($event->type) {
            'CHECKOUT.ORDER.APPROVED' => 'paid',
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            default => 'unknown',
        };

        $amount = isset($resource['amount']['value'])
            ? (float)$resource['amount']['value']
            : null;

        $currency = isset($resource['amount']['currency_code'])
            ? strtoupper($resource['amount']['currency_code'])
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
        // PayPal refunds are done via capture ID
        $request = new \PayPalCheckoutSdk\Payments\CapturesRefundRequest($payment_intent_id);

        if ($amount !== null) {
            $request->body = [
                'amount' => [
                    'value' => number_format($amount / 100, 2, '.', ''),
                    'currency_code' => 'USD',
                ]
            ];
        }

        $response = $this->client->execute($request);

        return new RefundResult(
            refund_id: $response->result->id,
            payment_intent_id: $payment_intent_id,
            amount: (float)$response->result->amount->value,
            currency: strtoupper($response->result->amount->currency_code),
            raw: (array)$response->result
        );
    }
}

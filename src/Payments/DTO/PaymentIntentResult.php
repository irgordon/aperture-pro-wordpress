<?php
namespace AperturePro\Payments\DTO;

class PaymentIntentResult
{
    public string $id;
    public ?int $amount;
    public ?string $currency;
    public ?string $client_secret;
    public ?string $provider;
    public ?string $checkout_url;
    public array $raw;

    public function __construct(
        string $id,
        ?int $amount = null,
        ?string $currency = null,
        ?string $client_secret = null,
        ?string $provider = null,
        ?string $checkout_url = null,
        array $raw = []
    ) {
        $this->id = $id;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->client_secret = $client_secret;
        $this->provider = $provider;
        $this->checkout_url = $checkout_url;
        $this->raw = $raw;
    }
}

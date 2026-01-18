<?php
namespace AperturePro\Payments\DTO;

class PaymentIntentResult
{
    public string $id;
    public ?string $checkout_url;
    public array $raw_data;

    public function __construct(string $id, ?string $checkout_url = null, array $raw_data = [])
    {
        $this->id = $id;
        $this->checkout_url = $checkout_url;
        $this->raw_data = $raw_data;
    }
}

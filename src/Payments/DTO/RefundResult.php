<?php
namespace AperturePro\Payments\DTO;

class RefundResult
{
    public string $refund_id;
    public string $payment_intent_id;
    public float $amount;
    public string $currency;
    public array $raw;

    public function __construct(
        string $refund_id,
        string $payment_intent_id,
        float $amount,
        string $currency,
        array $raw = []
    ) {
        $this->refund_id = $refund_id;
        $this->payment_intent_id = $payment_intent_id;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->raw = $raw;
    }
}

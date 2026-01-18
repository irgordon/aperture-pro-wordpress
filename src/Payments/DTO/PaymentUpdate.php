<?php
namespace AperturePro\Payments\DTO;

class PaymentUpdate
{
    public ?int $project_id;
    public string $status; // paid, failed, refunded
    public ?string $payment_intent_id;
    public ?float $amount;
    public ?string $currency;
    public $raw_event;

    public function __construct(
        ?int $project_id,
        string $status,
        ?string $payment_intent_id,
        ?float $amount,
        ?string $currency,
        $raw_event
    ) {
        $this->project_id = $project_id;
        $this->status = $status;
        $this->payment_intent_id = $payment_intent_id;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->raw_event = $raw_event;
    }
}

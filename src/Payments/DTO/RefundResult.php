<?php
namespace AperturePro\Payments\DTO;

class RefundResult
{
    public bool $success;
    public ?string $transaction_id;
    public ?string $error;

    public function __construct(bool $success, ?string $transaction_id = null, ?string $error = null)
    {
        $this->success = $success;
        $this->transaction_id = $transaction_id;
        $this->error = $error;
    }
}

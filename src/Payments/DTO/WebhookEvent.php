<?php
namespace AperturePro\Payments\DTO;

class WebhookEvent
{
    public string $type;
    public $payload; // Object or array

    public function __construct(string $type, $payload)
    {
        $this->type = $type;
        $this->payload = $payload;
    }
}

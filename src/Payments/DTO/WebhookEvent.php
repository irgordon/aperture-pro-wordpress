<?php
namespace AperturePro\Payments\DTO;

class WebhookEvent
{
    public string $provider;
    public string $type;
    public $payload; // Object or array

    public function __construct(string $provider, string $type, $payload)
    {
        $this->provider = $provider;
        $this->type = $type;
        $this->payload = $payload;
    }
}

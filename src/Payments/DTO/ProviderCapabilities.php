<?php
declare(strict_types=1);

namespace AperturePro\Payments\DTO;

class ProviderCapabilities
{
    public bool $supports_refunds;
    public bool $supports_checkout_redirect;
    public bool $supports_webhooks;

    public function __construct(
        bool $supports_refunds = false,
        bool $supports_checkout_redirect = false,
        bool $supports_webhooks = true
    ) {
        $this->supports_refunds = $supports_refunds;
        $this->supports_checkout_redirect = $supports_checkout_redirect;
        $this->supports_webhooks = $supports_webhooks;
    }
}

<?php
namespace AperturePro\Payments;

use AperturePro\Payments\Providers\StripeProvider;
use AperturePro\Payments\Providers\PayPalProvider;
use AperturePro\Payments\Providers\SquareProvider;
use AperturePro\Payments\Providers\AuthorizeNetProvider;
use AperturePro\Payments\Providers\AmazonPayProvider;

class PaymentProviderFactory
{
    public static function make(string $provider): PaymentProviderInterface
    {
        return match ($provider) {
            'stripe'      => new StripeProvider(),
            'paypal'      => new PayPalProvider(),
            'square'      => new SquareProvider(),
            'authorize'   => new AuthorizeNetProvider(),
            'amazon_pay'  => new AmazonPayProvider(),
            default       => throw new \Exception("Unknown provider: $provider"),
        };
    }
}

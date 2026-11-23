<?php

namespace App\Services\Payments\Gateways;

use App\Enums\PaymentGateway;

/** Picks the client for a gateway. Tests swap clients by binding fakes in the container. */
final class Gateways
{
    public function for(PaymentGateway $gateway): GatewayClient
    {
        return match ($gateway) {
            PaymentGateway::Paystack => app(PaystackClient::class),
            PaymentGateway::Flutterwave => app(FlutterwaveClient::class),
        };
    }
}

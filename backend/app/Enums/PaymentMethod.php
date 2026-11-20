<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';
    case Wallet = 'wallet';
    case PayOnDelivery = 'pay_on_delivery';

    /** Paid through a gateway checkout, so the order waits for verified payment. */
    public function isGateway(): bool
    {
        return $this === self::Paystack || $this === self::Flutterwave;
    }
}

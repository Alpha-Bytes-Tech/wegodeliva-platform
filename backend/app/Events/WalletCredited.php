<?php

namespace App\Events;

/** A wallet was credited by a top-up or a referral reward. */
final class WalletCredited
{
    public function __construct(public readonly int $walletTransactionId) {}
}

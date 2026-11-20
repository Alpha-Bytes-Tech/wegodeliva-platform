<?php

namespace App\Services\Ledger;

/** The KYC tier limits of one wallet (spec 06 §6.4), in kobo. */
final readonly class WalletLimits
{
    public function __construct(
        public int $tier,
        public int $minTopUpKobo,
        public int $dailyTopUpKobo,
        public ?int $maxBalanceKobo,
    ) {}

    public static function forTier(int $tier): self
    {
        /** @var array{daily_topup_kobo: int, max_balance_kobo: int|null} $limits */
        $limits = config("wegodeliva.wallet.tiers.{$tier}");

        return new self($tier, (int) config('wegodeliva.wallet.min_topup_kobo'), $limits['daily_topup_kobo'], $limits['max_balance_kobo']);
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use App\Services\Ledger\WalletLedger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wallet with its tier limits and what can still be topped up today (spec 06 §6.4).
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ledger = app(WalletLedger::class);
        $limits = $ledger->limits($this->resource);
        $dailyLeft = max(0, $limits->dailyTopUpKobo - $ledger->topUpsToday($this->resource));
        $room = $limits->maxBalanceKobo === null ? null : max(0, $limits->maxBalanceKobo - $this->balance_kobo);

        return [
            'id' => $this->uuid,
            'balance' => Money::of($this->balance_kobo),
            'currency' => $this->currency,
            'kyc_tier' => $this->kyc_tier,
            'limits' => [
                'min_topup' => Money::of($limits->minTopUpKobo),
                'daily_topup' => Money::of($limits->dailyTopUpKobo),
                'max_balance' => $limits->maxBalanceKobo === null ? null : Money::of($limits->maxBalanceKobo),
                // The most a top-up could be right now: today's allowance and room under the cap.
                'topup_available' => Money::of($room === null ? $dailyLeft : min($dailyLeft, $room)),
            ],
        ];
    }
}

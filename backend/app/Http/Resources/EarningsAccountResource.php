<?php

namespace App\Http\Resources;

use App\Models\EarningsAccount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Earnings as vendors and riders see them (spec 05 §5.8 "Balances shown").
 *
 * @mixin EarningsAccount
 */
class EarningsAccountResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'gross_earnings' => Money::of($this->balance_kobo),
            'commission_due' => Money::of($this->commission_due_kobo),
            'commission_vat_due' => Money::of($this->commission_vat_due_kobo),
            'pending_payouts' => Money::of($this->held_kobo),
            'available_to_withdraw' => Money::of($this->availableKobo()),
            $this->mergeWhen($this->owner_type->value === 'rider', fn () => [
                'cash_owed' => Money::of($this->cash_owed_kobo),
            ]),
        ];
    }
}

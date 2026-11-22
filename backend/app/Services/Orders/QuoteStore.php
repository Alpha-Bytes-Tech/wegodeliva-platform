<?php

namespace App\Services\Orders;

use App\Models\OrderQuote;
use App\Models\User;

/** Saves priced carts as quotes and presents them to the app. */
final class QuoteStore
{
    public static function save(User $customer, BuiltQuote $built): OrderQuote
    {
        $quote = new OrderQuote;
        $quote->forceFill([
            'user_id' => $customer->id,
            'vendor_id' => $built->vendor->id,
            'input' => $built->input->toArray(),
            'breakdown' => $built->price->toArray(),
            'total_kobo' => $built->price->totalKobo,
            'fingerprint' => $built->fingerprint(),
            'expires_at' => now()->addMinutes((int) config('wegodeliva.orders.quote_ttl_minutes')),
        ])->save();

        return $quote;
    }

    /** @return array<string, mixed> */
    public static function present(OrderQuote $quote, BuiltQuote $built): array
    {
        return ['id' => $quote->uuid, 'expires_at' => $quote->expires_at->toIso8601String()] + $built->toArray();
    }
}

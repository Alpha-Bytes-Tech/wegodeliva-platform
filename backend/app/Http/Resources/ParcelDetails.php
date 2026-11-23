<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStop;

/**
 * The parcel part of an order (spec 09 "Parcel order details", spec 11 "Parcel stops"). Everyone
 * fulfilling it sees the stops in full, since the operator dispatches its own riders; only the
 * sender sees the stop codes, until each stop is done.
 */
final class ParcelDetails
{
    /** @return array<string, mixed> */
    public static function for(Order $order, bool $withCodes): array
    {
        $order->loadMissing('stops', 'packageType');
        $showCodes = $withCodes && ! $order->status->isFinal() && $order->status !== OrderStatus::AwaitingPayment;

        return [
            'package_type' => $order->packageType === null ? null : ['id' => $order->packageType->uuid, 'name' => $order->packageType->name],
            'payer' => $order->payer,
            'weight_g' => $order->weight_g,
            'dimensions_cm' => $order->length_cm === null ? null : ['length' => $order->length_cm, 'width' => $order->width_cm, 'height' => $order->height_cm],
            'stops' => $order->stops->map(fn (OrderStop $s) => [
                'sequence' => $s->sequence,
                'kind' => $s->kind,
                'address' => $s->address,
                'contact_name' => $s->contact_name,
                'contact_phone' => $s->contact_phone,
                'note' => $s->note,
                'needs_code' => $s->verification_code !== null,
                'code' => $showCodes && $s->verified_at === null ? $s->verification_code : null,
                'done_at' => $s->verified_at?->toIso8601String(),
            ])->all(),
        ];
    }
}

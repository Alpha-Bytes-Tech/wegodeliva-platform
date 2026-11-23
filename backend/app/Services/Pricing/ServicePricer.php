<?php

namespace App\Services\Pricing;

use App\Models\Option;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Service booking prices and times (spec 05 §5.9). The line is the unit price × booked units
 * plus the options: once per booking, or × units when an option is per unit. The call-out fee
 * for work at the customer's address is flat or per km of road distance (straight-line × the
 * road factor) and is treated like a delivery fee.
 */
final class ServicePricer
{
    public function __construct(private readonly PricingSettings $settings) {}

    /** @param  PricedLine  $validated  the service priced for one unit, with its checked options */
    public function line(Product $service, int $units, PricedLine $validated): PricedLine
    {
        /** @var array<string, bool> $perUnit */
        $perUnit = [];
        foreach ($service->optionGroups as $group) {
            foreach ($group->options as $option) {
                /** @var Option $option */
                $perUnit[$option->uuid] = $option->per_unit;
            }
        }

        $options = array_map(
            fn (PricedOption $o) => new PricedOption($o->groupId, $o->groupName, $o->optionId, $o->name, $perUnit[$o->optionId] ? $o->priceKobo * $units : $o->priceKobo),
            $validated->options,
        );

        return new PricedLine(
            productId: $validated->productId,
            productUuid: $validated->productUuid,
            name: $validated->name,
            unitPriceKobo: $validated->unitPriceKobo * $units,
            quantity: 1,
            options: $options,
            vatTreatment: $validated->vatTreatment,
        );
    }

    public function endsAt(Product $service, Carbon $startsAt, int $units, int $defaultMinutes): Carbon
    {
        $start = $startsAt->copy();

        return match ($service->pricing_unit) {
            'hour' => $start->addHours($units),
            'day' => $start->addDays($units),
            'month' => $start->addMonthsNoOverflow($units),
            default => $start->addMinutes($service->duration_minutes ?? $defaultMinutes),
        };
    }

    public function calloutFee(Product $service, int $straightDistanceM): int
    {
        $roadM = intdiv($straightDistanceM * $this->settings->roadFactorPermille + 500, 1000);

        return match ($service->callout_fee_mode) {
            'flat' => $service->callout_fee_kobo,
            'per_km' => intdiv($service->callout_fee_kobo * $roadM + 500, 1000),
            default => 0,
        };
    }
}

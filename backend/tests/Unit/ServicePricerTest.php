<?php

use App\Enums\VatTreatment;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\Pricing\PricedLine;
use App\Services\Pricing\PricedOption;
use App\Services\Pricing\PricingSettings;
use App\Services\Pricing\ServicePricer;
use Illuminate\Support\Carbon;

function servicePricerProduct(array $attributes = []): Product
{
    $product = (new Product)->forceFill(array_merge(['pricing_unit' => 'hour', 'duration_minutes' => null, 'callout_fee_mode' => null, 'callout_fee_kobo' => 0], $attributes));
    $group = (new OptionGroup)->forceFill(['uuid' => 'g1']);
    $group->setRelation('options', collect([
        (new Option)->forceFill(['uuid' => 'per-hour', 'per_unit' => true]),
        (new Option)->forceFill(['uuid' => 'once', 'per_unit' => false]),
    ]));
    $product->setRelation('optionGroups', collect([$group]));

    return $product;
}

it('multiplies the price and per-unit options by the units, and charges other options once', function () {
    $validated = new PricedLine(7, 'svc', 'Deep cleaning', 500000, 1, [
        new PricedOption('g1', 'Extras', 'per-hour', 'Extra cleaner', 100000),
        new PricedOption('g1', 'Extras', 'once', 'Supplies', 200000),
    ], VatTreatment::Standard);

    $line = (new ServicePricer(new PricingSettings))->line(servicePricerProduct(), 3, $validated);

    expect($line->unitPriceKobo)->toBe(1500000)
        ->and($line->quantity)->toBe(1)
        ->and(array_map(fn (PricedOption $o) => $o->priceKobo, $line->options))->toBe([300000, 200000])
        ->and($line->lineTotalKobo)->toBe(2000000)
        ->and($line->vatTreatment)->toBe(VatTreatment::Standard);
});

it('works out when a booking ends from its pricing unit', function () {
    $pricer = new ServicePricer(new PricingSettings);
    $start = Carbon::parse('2026-01-31 09:00:00');

    expect($pricer->endsAt(servicePricerProduct(['pricing_unit' => 'hour']), $start, 3, 60)->toDateTimeString())->toBe('2026-01-31 12:00:00')
        ->and($pricer->endsAt(servicePricerProduct(['pricing_unit' => 'day']), $start, 2, 60)->toDateTimeString())->toBe('2026-02-02 09:00:00')
        ->and($pricer->endsAt(servicePricerProduct(['pricing_unit' => 'month']), $start, 1, 60)->toDateTimeString())->toBe('2026-02-28 09:00:00')
        ->and($pricer->endsAt(servicePricerProduct(['pricing_unit' => 'fixed', 'duration_minutes' => 45]), $start, 1, 60)->toDateTimeString())->toBe('2026-01-31 09:45:00')
        ->and($pricer->endsAt(servicePricerProduct(['pricing_unit' => 'fixed']), $start, 1, 90)->toDateTimeString())->toBe('2026-01-31 10:30:00')
        ->and($start->toDateTimeString())->toBe('2026-01-31 09:00:00');
});

it('charges a call-out fee that is flat, per km of road, or nothing', function () {
    $pricer = new ServicePricer(new PricingSettings);

    // 1,112 m straight → 1,446 m by road.
    expect($pricer->calloutFee(servicePricerProduct(['callout_fee_mode' => 'flat', 'callout_fee_kobo' => 100000]), 1112))->toBe(100000)
        ->and($pricer->calloutFee(servicePricerProduct(['callout_fee_mode' => 'per_km', 'callout_fee_kobo' => 20000]), 1112))->toBe(28920)
        ->and($pricer->calloutFee(servicePricerProduct(['callout_fee_mode' => 'none', 'callout_fee_kobo' => 20000]), 1112))->toBe(0)
        ->and($pricer->calloutFee(servicePricerProduct(), 1112))->toBe(0);
});

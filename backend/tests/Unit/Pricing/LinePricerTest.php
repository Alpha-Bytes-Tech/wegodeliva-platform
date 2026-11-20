<?php

use App\Enums\VatTreatment;
use App\Exceptions\ApiException;
use App\Services\Pricing\LinePricer;
use App\Services\Pricing\PricedLine;
use Tests\Support\Pricing;

function pricingError(Closure $call): array
{
    try {
        $call();
    } catch (ApiException $e) {
        return [$e->errorCode, $e->status, $e->fields];
    }
    throw new RuntimeException('Expected an ApiException.');
}

it('charges options per unit, times the quantity', function () {
    $product = Pricing::product(150000, groups: [
        Pricing::group('protein', ['chicken' => 50000, 'beef' => 40000], max: 1),
        Pricing::group('extras', ['plantain' => 20000, 'egg' => 10000], max: 2),
    ]);

    $line = (new LinePricer)->price($product, 3, ['chicken', 'plantain', 'egg']);

    expect($line->unitPriceKobo)->toBe(150000)
        ->and($line->optionsKobo())->toBe(80000)
        ->and($line->lineTotalKobo)->toBe((150000 + 80000) * 3)
        ->and($line->productUuid)->toBe($product->uuid)
        ->and(array_map(fn ($o) => $o->toArray()['option'], $line->options))->toBe(['Chicken', 'Plantain', 'Egg'])
        ->and($line->options[0]->toArray())->toBe(['group_id' => 'g-protein', 'group' => 'protein', 'option_id' => 'chicken', 'option' => 'Chicken', 'price_kobo' => 50000]);
});

it('uses the promotional price when it is lower', function (?int $promo, int $expected) {
    expect((new LinePricer)->price(Pricing::product(200000, $promo), 1, [])->lineTotalKobo)->toBe($expected);
})->with([
    'lower promo' => [150000, 150000],
    'higher "promo" ignored' => [250000, 200000],
    'no promo' => [null, 200000],
]);

it('enforces group limits and required groups', function (array $groups, array $chosen, string $message) {
    [$code, $status, $fields] = pricingError(fn () => (new LinePricer)->price(Pricing::product(1000, groups: $groups), 1, $chosen, 'items.0.options'));

    expect($code)->toBe('invalid_options')->and($status)->toBe(422)
        ->and($fields)->toBe(['items.0.options' => [$message]]);
})->with([
    'required group left empty' => [[Pricing::group('size', ['small' => 0, 'large' => 500], required: true)], [], 'Choose at least 1 from "size".'],
    'below minimum' => [[Pricing::group('sides', ['a' => 0, 'b' => 0, 'c' => 0], min: 2, max: 3)], ['a'], 'Choose at least 2 from "sides".'],
    'above maximum' => [[Pricing::group('sauce', ['red' => 0, 'green' => 0], max: 1)], ['red', 'green'], 'Choose at most 1 from "sauce".'],
    'option from another product' => [[Pricing::group('sauce', ['red' => 0])], ['blue'], 'One of the options is not available for this product.'],
    'inactive option' => [[Pricing::group('sauce', ['xold' => 0])], ['xold'], 'One of the options is not available for this product.'],
    'option of an inactive group' => [[Pricing::group('sauce', ['red' => 0], active: false)], ['red'], 'One of the options is not available for this product.'],
    'same option twice' => [[Pricing::group('extras', ['egg' => 100], max: 3)], ['egg', 'egg'], 'The same option was chosen twice.'],
]);

it('ignores the limits of inactive groups', function () {
    $product = Pricing::product(1000, groups: [Pricing::group('size', ['small' => 0], required: true, active: false)]);

    expect((new LinePricer)->price($product, 1, [])->lineTotalKobo)->toBe(1000);
});

it('takes VAT treatment from the product, else agreeing categories, else standard', function (?VatTreatment $own, array $categories, VatTreatment $expected) {
    expect(LinePricer::vatTreatment(Pricing::product(1000, vat: $own, categoryTreatments: $categories)))->toBe($expected);
})->with([
    'product override wins' => [VatTreatment::Exempt, ['standard'], VatTreatment::Exempt],
    'categories agree' => [null, ['zero_rated', 'zero_rated', null], VatTreatment::ZeroRated],
    'categories disagree' => [null, ['zero_rated', 'exempt'], VatTreatment::Standard],
    'no categories' => [null, [], VatTreatment::Standard],
    'categories undecided' => [null, [null], VatTreatment::Standard],
]);

it('refuses a line with no quantity or a negative price', function (int $price, int $qty) {
    new PricedLine(1, 'p', 'P', $price, $qty, [], VatTreatment::Standard);
})->with([[100, 0], [-1, 1]])->throws(InvalidArgumentException::class);

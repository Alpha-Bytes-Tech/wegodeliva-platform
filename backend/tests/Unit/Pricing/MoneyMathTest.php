<?php

use App\Support\Money;

it('takes basis points of an amount, rounding half up to the kobo', function (int $kobo, int $bp, int $expected) {
    expect(Money::percentOf($kobo, $bp))->toBe($expected);
})->with([
    'VAT on ₦4,500' => [450000, 750, 33750],
    'VAT on ₦225 commission (1,687.5 kobo)' => [22500, 750, 1688],
    'just under half' => [1, 4999, 0],
    'exactly half' => [1, 5000, 1],
    'zero rate' => [123456, 0, 0],
    'whole' => [999, 10000, 999],
]);

it('refuses negative amounts or rates', function (int $kobo, int $bp) {
    Money::percentOf($kobo, $bp);
})->with([[-1, 100], [100, -1]])->throws(InvalidArgumentException::class);

it('allocates an amount across weights so the parts add up exactly', function (int $kobo, array $weights, array $expected) {
    $parts = Money::allocate($kobo, $weights);
    expect($parts)->toBe($expected)->and(array_sum($parts))->toBe($kobo);
})->with([
    'even split' => [100, [1, 1], [50, 50]],
    'remainder to the earliest tie' => [100, [1, 1, 1], [34, 33, 33]],
    'largest remainder wins' => [10, [1, 2], [3, 7]],
    'proportional' => [50000, [300000, 200000], [30000, 20000]],
    'zero weight gets nothing' => [7, [0, 5], [0, 7]],
    'nothing to allocate' => [0, [3, 4], [0, 0]],
]);

it('refuses to allocate over zero or negative weights', function (int $kobo, array $weights) {
    Money::allocate($kobo, $weights);
})->with([
    'no weight' => [10, [0, 0]],
    'negative weight' => [10, [5, -1]],
    'negative amount' => [-10, [1]],
])->throws(InvalidArgumentException::class);

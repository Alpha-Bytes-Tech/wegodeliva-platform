<?php

use App\Support\Geo;
use App\Support\Money;

it('measures straight-line distance in metres', function () {
    // One degree of latitude is 111,195 m on a 6,371 km sphere.
    expect(Geo::distanceMeters(6.0, 3.0, 7.0, 3.0))->toBe(111195)
        // Yaba to Ikeja: 10.26 km north and 2.17 km west.
        ->and(Geo::distanceMeters(6.5095, 3.3711, 6.6018, 3.3515))->toBeGreaterThan(10400)->toBeLessThan(10600)
        ->and(Geo::distanceMeters(6.5, 3.3, 6.5, 3.3))->toBe(0);
});

it('formats kobo as naira with two decimals', function (int $kobo, string $formatted) {
    expect(Money::format($kobo))->toBe($formatted)
        ->and(Money::of($kobo))->toBe(['amount_kobo' => $kobo, 'formatted' => $formatted]);
})->with([
    [0, '₦0.00'],
    [5, '₦0.05'],
    [150000, '₦1,500.00'],
    [613750, '₦6,137.50'],
    [123456789, '₦1,234,567.89'],
    [-2500, '-₦25.00'],
]);

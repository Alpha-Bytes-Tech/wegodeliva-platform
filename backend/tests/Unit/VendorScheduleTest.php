<?php

use App\Enums\OpenState;
use App\Models\Vendor;
use App\Models\VendorHour;
use App\Services\Catalogue\VendorSchedule;
use Illuminate\Support\Carbon;

/** @param list<array{0: int, 1: string, 2: string}> $periods */
function vendorWithHours(array $periods): Vendor
{
    $vendor = new Vendor;
    $vendor->setRelation('hours', collect($periods)->map(function (array $p) {
        $hour = new VendorHour;
        $hour->forceFill(['weekday' => $p[0], 'opens_at' => $p[1].':00', 'closes_at' => $p[2].':00']);

        return $hour;
    }));

    return $vendor;
}

/** A moment in Lagos time (UTC+1, no DST). 2026-11-16 is a Monday (weekday 1). */
function lagos(string $local): Carbon
{
    return Carbon::parse($local, 'Africa/Lagos')->utc();
}

beforeEach(fn () => config(['wegodeliva.timezone' => 'Africa/Lagos']));

it('is open inside a period and closed outside it, in Lagos time', function (string $at, OpenState $expected) {
    $vendor = vendorWithHours([[1, '09:00', '17:00']]);

    expect((new VendorSchedule)->stateAt($vendor, lagos($at)))->toBe($expected);
})->with([
    'before opening' => ['2026-11-16 08:59', OpenState::Closed],
    'at opening' => ['2026-11-16 09:00', OpenState::Open],
    'afternoon' => ['2026-11-16 16:59', OpenState::Open],
    'at closing' => ['2026-11-16 17:00', OpenState::Closed],
    'next day' => ['2026-11-17 10:00', OpenState::Closed],
]);

it('reads the weekday in Lagos, not UTC', function () {
    // 23:30 UTC on Sunday is 00:30 Monday in Lagos.
    $vendor = vendorWithHours([[1, '00:00', '01:00']]);

    expect((new VendorSchedule)->stateAt($vendor, Carbon::parse('2026-11-15 23:30', 'UTC')))->toBe(OpenState::Open);
});

it('supports split shifts', function () {
    $vendor = vendorWithHours([[1, '08:00', '11:00'], [1, '17:00', '22:00']]);
    $schedule = new VendorSchedule;

    expect($schedule->stateAt($vendor, lagos('2026-11-16 10:00')))->toBe(OpenState::Open)
        ->and($schedule->stateAt($vendor, lagos('2026-11-16 13:00')))->toBe(OpenState::Closed)
        ->and($schedule->stateAt($vendor, lagos('2026-11-16 18:00')))->toBe(OpenState::Open);
});

it('carries an overnight period past midnight into the next day', function () {
    $vendor = vendorWithHours([[5, '18:00', '02:00']]); // Friday night
    $schedule = new VendorSchedule;

    expect($schedule->stateAt($vendor, lagos('2026-11-20 23:00')))->toBe(OpenState::Open)
        ->and($schedule->stateAt($vendor, lagos('2026-11-21 01:30')))->toBe(OpenState::Open)
        ->and($schedule->stateAt($vendor, lagos('2026-11-21 02:00')))->toBe(OpenState::Closed)
        ->and($schedule->stateAt($vendor, lagos('2026-11-21 19:00')))->toBe(OpenState::Closed);
});

it('treats equal open and close times as open all day', function () {
    $vendor = vendorWithHours([[1, '00:00', '00:00']]);

    expect((new VendorSchedule)->stateAt($vendor, lagos('2026-11-16 15:00')))->toBe(OpenState::Open);
});

it('is closed with no hours at all', function () {
    expect((new VendorSchedule)->stateAt(vendorWithHours([]), lagos('2026-11-16 12:00')))->toBe(OpenState::Closed);
});

it('lets a manual state override the schedule until it expires', function () {
    $vendor = vendorWithHours([[1, '09:00', '17:00']]);
    $vendor->manual_state = OpenState::Busy;
    $vendor->manual_state_until = lagos('2026-11-16 12:00');
    $schedule = new VendorSchedule;

    expect($schedule->stateAt($vendor, lagos('2026-11-16 11:00')))->toBe(OpenState::Busy)
        ->and($schedule->stateAt($vendor, lagos('2026-11-16 12:00')))->toBe(OpenState::Open);

    $vendor->manual_state = OpenState::Closed;
    $vendor->manual_state_until = null; // until changed back
    expect($schedule->stateAt($vendor, lagos('2026-11-16 11:00')))->toBe(OpenState::Closed);
});

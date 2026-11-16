<?php

use App\Support\PhoneNumber;
use App\Support\ReferralCode;

it('normalises Nigerian mobile numbers to E.164', function (string $input, ?string $expected) {
    expect(PhoneNumber::normalise($input))->toBe($expected);
})->with([
    ['08031234567', '+2348031234567'],
    ['0803 123 4567', '+2348031234567'],
    ['+234 803-123-4567', '+2348031234567'],
    ['2347011234567', '+2347011234567'],
    ['09121234567', '+2349121234567'],
    ['(0810) 123 4567', '+2348101234567'],
    ['0803123456', null],
    ['08031234567890', null],
    ['06031234567', null],
    ['+447911123456', null],
    ['8031234567', null],
    ['', null],
]);

it('generates readable referral codes', function () {
    foreach (range(1, 50) as $i) {
        expect(ReferralCode::generate())->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/');
    }
});

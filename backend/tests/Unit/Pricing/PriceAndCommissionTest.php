<?php

use App\Enums\Party;
use App\Enums\VatTreatment;
use App\Services\Pricing\CommissionCalculator;
use App\Services\Pricing\CouponRules;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricingSettings;
use Tests\Support\Pricing;

function calculator(): PriceCalculator
{
    return new PriceCalculator(new PricingSettings);
}

function commission(): CommissionCalculator
{
    return new CommissionCalculator(new PricingSettings);
}

/** Every kobo the customer pays ends up with exactly one party. */
function expectSharesBalance(PriceBreakdown $price, ?int $vendorRate = null, ?int $deliveryRate = null): void
{
    $split = commission()->split($price, $vendorRate, $deliveryRate);
    expect($split->vendor->netKobo() + $split->delivery->netKobo() + $split->platformRevenueKobo + $split->commissionVatKobo)
        ->toBe($price->totalKobo);
}

it('reproduces the spec 05 worked example to the kobo', function () {
    $lines = [Pricing::line(500000)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['value' => 50000]), Pricing::context($lines, 100000));

    $price = calculator()->calculate($lines, true, 100000, $coupon, [Pricing::fee('fixed', 10000)], tipKobo: 20000);

    expect($price->subtotalKobo)->toBe(500000)
        ->and($price->discountKobo)->toBe(50000)
        ->and($price->taxKobo)->toBe(33750)
        ->and($price->taxRateBp)->toBe(750)
        ->and($price->serviceFeeKobo)->toBe(10000)
        ->and($price->totalKobo)->toBe(613750); // ₦6,137.50

    $split = commission()->split($price);
    expect($split->vendor->grossKobo)->toBe(483750)
        ->and($split->vendor->commissionKobo)->toBe(22500)
        ->and($split->vendor->commissionVatKobo)->toBe(1688)
        ->and($split->vendor->netKobo())->toBe(459562)   // can withdraw ₦4,595.62
        ->and($split->vatCollectedForVendorKobo)->toBe(33750)
        ->and($split->delivery->grossKobo)->toBe(120000)
        ->and($split->delivery->commissionKobo)->toBe(3000)
        ->and($split->delivery->commissionVatKobo)->toBe(225)
        ->and($split->delivery->netKobo())->toBe(116775) // ₦1,167.75
        ->and($split->platformRevenueKobo)->toBe(35500)   // ₦355
        ->and($split->commissionVatKobo)->toBe(1913)      // ₦19.13
        ->and($split->deliveryToVendor)->toBeFalse();

    expect(459562 + 116775 + 35500 + 1913)->toBe($price->totalKobo);
});

it('charges no VAT for unregistered vendors or zero-rated and exempt goods', function (bool $registered, VatTreatment $treatment) {
    $lines = [Pricing::line(500000, vat: $treatment)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['value' => 50000]), Pricing::context($lines));

    $price = calculator()->calculate($lines, $registered, 100000, $coupon, [Pricing::fee('fixed', 10000)], 20000);

    expect($price->taxKobo)->toBe(0)->and($price->taxRateBp)->toBe(0)
        ->and($price->totalKobo)->toBe(580000)          // ₦5,800, as in the spec
        ->and($price->lineTaxes[0]->rateBp)->toBe(0);
    expectSharesBalance($price);
})->with([
    'unregistered vendor' => [false, VatTreatment::Standard],
    'zero-rated groceries' => [true, VatTreatment::ZeroRated],
    'exempt goods' => [true, VatTreatment::Exempt],
]);

it('keeps VAT on the full line price and the vendor whole when the platform funds the discount', function () {
    $lines = [Pricing::line(300000), Pricing::line(100000, vat: VatTreatment::ZeroRated)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['value' => 40000, 'funded_by' => 'platform']), Pricing::context($lines));

    $price = calculator()->calculate($lines, true, 50000, $coupon, []);

    expect($price->discountFundedBy)->toBe(Party::Platform)
        ->and($price->vendorFundedDiscountKobo())->toBe(0)
        ->and($price->platformFundedDiscountKobo())->toBe(40000)
        ->and($price->lineTaxes[0]->baseKobo)->toBe(300000)
        ->and($price->taxKobo)->toBe(22500)
        ->and($price->totalKobo)->toBe(400000 - 40000 + 50000 + 22500);

    $split = commission()->split($price);
    expect($split->vendor->commissionBaseKobo)->toBe(400000)
        ->and($split->platformRevenueKobo)->toBe(20000 + 1500 - 40000);
    expectSharesBalance($price);
});

it('lowers each line\'s VAT base by its share of a vendor-funded discount', function () {
    $lines = [Pricing::line(300000), Pricing::line(100000, vat: VatTreatment::ZeroRated)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['value' => 40000]), Pricing::context($lines));

    $price = calculator()->calculate($lines, true, null, $coupon, []);

    expect($price->lineTaxes[0]->baseKobo)->toBe(270000)
        ->and($price->lineTaxes[1]->baseKobo)->toBe(90000)
        ->and($price->taxKobo)->toBe(20250)
        ->and($price->vendorFundedDiscountKobo())->toBe(40000);
    expectSharesBalance($price);
});

it('gives the rider the full fee when a delivery coupon applies, and the platform pays for it', function () {
    $lines = [Pricing::line(200000)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['applies_to' => 'delivery_fee', 'discount_type' => 'percent', 'value' => 10000, 'funded_by' => 'platform']), Pricing::context($lines, 60000));

    $price = calculator()->calculate($lines, false, 60000, $coupon, [], 10000);

    expect($price->deliveryDiscountKobo)->toBe(60000)
        ->and($price->discountKobo)->toBe(0)
        ->and($price->discountFundedBy)->toBeNull()
        ->and($price->platformFundedDiscountKobo())->toBe(60000)
        ->and($price->totalKobo)->toBe(210000);

    $split = commission()->split($price);
    expect($split->delivery->grossKobo)->toBe(70000)->and($split->delivery->commissionKobo)->toBe(1800);
    expectSharesBalance($price);
});

it('adds percent and fixed service fees, skipping inactive ones, and passes vendor fees to the vendor', function () {
    $lines = [Pricing::line(333333)];
    $fees = [
        Pricing::fee('percent', 250),                       // 2.5% of ₦3,333.33 = 8,333.325 → 8,333
        Pricing::fee('fixed', 15000, Party::Vendor),        // packaging
        Pricing::fee('fixed', 99999, active: false),
    ];

    $price = calculator()->calculate($lines, false, 0, null, $fees);

    expect($price->serviceFeeKobo)->toBe(8333 + 15000)
        ->and(count($price->fees))->toBe(2)
        ->and($price->feesFor(Party::Vendor))->toBe(15000)
        ->and($price->feesFor(Party::Platform))->toBe(8333);

    $split = commission()->split($price);
    expect($split->vendor->grossKobo)->toBe(333333 + 15000)
        ->and($split->vendor->commissionBaseKobo)->toBe(333333);
    expectSharesBalance($price);
});

it('prices pickup orders with no delivery fee', function () {
    $price = calculator()->calculate([Pricing::line(100000, 2, optionsKobo: 5000)], true, null, null, []);

    expect($price->deliveryFeeKobo)->toBe(0)
        ->and($price->subtotalKobo)->toBe(210000)
        ->and($price->totalKobo)->toBe(210000 + 15750)
        ->and($price->coupon)->toBeNull();
});

it('uses vendor and rider rate overrides and can route the delivery share to the vendor', function () {
    $price = calculator()->calculate([Pricing::line(100000)], false, 50000, null, [], 5000);

    $split = commission()->split($price, vendorRateBp: 1000, deliveryRateBp: 0, deliveryToVendor: true);

    expect($split->vendor->commissionKobo)->toBe(10000)
        ->and($split->vendor->commissionRateBp)->toBe(1000)
        ->and($split->delivery->commissionKobo)->toBe(0)
        ->and($split->delivery->commissionVatKobo)->toBe(0)
        ->and($split->deliveryToVendor)->toBeTrue();
    expectSharesBalance($price, 1000, 0);
});

it('balances to the kobo across awkward amounts', function (int $unit, int $qty, int $couponValue, string $fundedBy, int $delivery, int $tip) {
    $lines = [Pricing::line($unit, $qty), Pricing::line(intdiv($unit, 3) + 7, 1, VatTreatment::ZeroRated)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(['discount_type' => 'percent', 'value' => $couponValue, 'funded_by' => $fundedBy]), Pricing::context($lines));
    $price = calculator()->calculate($lines, true, $delivery, $coupon, [Pricing::fee('percent', 333), Pricing::fee('fixed', 1234, Party::Vendor)], $tip);

    expectSharesBalance($price);
    expectSharesBalance($price, 777, 123);
})->with([
    [99999, 3, 1333, 'vendor', 77777, 0],
    [1, 1, 10000, 'platform', 1, 1],
    [123457, 7, 1, 'vendor', 0, 99999],
    [5, 2, 5000, 'platform', 33333, 333],
]);

it('serialises a breakdown for the API', function () {
    $lines = [Pricing::line(150000, 2, optionsKobo: 10000)];
    $coupon = (new CouponRules)->apply(Pricing::coupon(), Pricing::context($lines));
    $data = calculator()->calculate($lines, true, 50000, $coupon, [Pricing::fee('fixed', 10000)], 5000)->toArray();

    expect($data['lines'][0])->toMatchArray(['quantity' => 2, 'vat_rate_bp' => 750])
        ->and($data['lines'][0]['line_total']['amount_kobo'])->toBe(320000)
        ->and($data['lines'][0]['options'][0]['price_kobo'])->toBe(10000)
        ->and($data['coupon_code'])->toBe('SAVE')
        ->and($data['fees'][0]['amount']['formatted'])->toBe('₦100.00')
        ->and($data['total']['amount_kobo'])->toBe(320000 - 50000 + 50000 + 10000 + 20250 + 5000)
        ->and(array_keys($data))->toBe(['lines', 'subtotal', 'discount', 'delivery_fee', 'delivery_discount', 'fees', 'service_fee', 'tax', 'tax_rate_bp', 'tip', 'total', 'coupon_code']);
});

it('refuses an empty order or negative tip or delivery fee', function (array $lines, ?int $delivery, int $tip) {
    calculator()->calculate($lines, false, $delivery, null, [], $tip);
})->with([
    'no lines' => [[], null, 0],
    'negative tip' => [[Pricing::line(100)], null, -1],
    'negative delivery' => [[Pricing::line(100)], -1, 0],
])->throws(InvalidArgumentException::class);

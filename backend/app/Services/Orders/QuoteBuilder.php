<?php

namespace App\Services\Orders;

use App\Enums\Fulfilment;
use App\Enums\OpenState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Catalogue\VendorSchedule;
use App\Services\Ledger\WalletLedger;
use App\Services\Pricing\CouponContext;
use App\Services\Pricing\CouponDiscount;
use App\Services\Pricing\CouponRules;
use App\Services\Pricing\DeliveryQuote;
use App\Services\Pricing\DeliveryQuoter;
use App\Services\Pricing\LinePricer;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricedLine;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Validates and prices a cart (spec 04 §4.4–4.5): vendor open (or a valid scheduled slot),
 * products active, deliverable and in stock, options valid, address served, order limits,
 * coupon and tip. Runs for the quote and again at placement, on fresh data. Business-rule
 * failures are 422s with a stable code; `fields` points at the offending input.
 */
final class QuoteBuilder
{
    public function __construct(
        private readonly LinePricer $lines,
        private readonly PriceCalculator $calculator,
        private readonly CouponRules $coupons,
        private readonly DeliveryQuoter $deliveries,
        private readonly VendorSchedule $schedule,
        private readonly WalletLedger $wallets,
    ) {}

    public function build(User $customer, QuoteInput $input): BuiltQuote
    {
        $vendor = Vendor::query()->active()->where('uuid', $input->vendorId)->with('hours', 'vendorType', 'fees')->first()
            ?? $this->fail('vendor_unavailable', ['vendor_id' => ['This store is not taking orders.']]);

        $this->checkTiming($vendor, $input->scheduledFor);
        [$products, $priced] = $this->priceLines($vendor, $input);
        [$address, $delivery] = $this->fulfilment($customer, $vendor, $input);

        $subtotal = array_sum(array_map(fn ($l) => $l->lineTotalKobo, $priced));
        $this->checkLimits($vendor, $subtotal);

        if ($input->tipKobo > 0 && $input->fulfilment === Fulfilment::Pickup) {
            $this->fail('validation_failed', ['tip_kobo' => ['Tips go to the rider, so pickup orders cannot include one.']], 400);
        }

        $coupon = $input->couponCode === null ? null : $this->coupon($customer, $vendor, $input->couponCode, $priced, $delivery?->feeKobo);

        $price = $this->calculator->calculate(
            $priced,
            $vendor->vat_registered,
            $delivery?->feeKobo,
            $coupon,
            $vendor->fees,
            $input->tipKobo,
        );

        return new BuiltQuote($input, $vendor, $price, $products, $address, $delivery, $this->paymentOptions($customer, $price->totalKobo));
    }

    private function checkTiming(Vendor $vendor, ?Carbon $at): void
    {
        if ($at === null) {
            $state = $vendor->openState();
            if ($state !== OpenState::Open) {
                $this->fail('vendor_closed', [], meta: ['open_state' => $state->value, 'can_schedule' => $vendor->allows_scheduled_orders]);
            }

            return;
        }

        $cfg = config('wegodeliva.orders');
        $earliest = now()->addMinutes((int) $cfg['min_schedule_lead_minutes']);
        $latest = now()->addDays((int) $cfg['max_schedule_days']);
        $message = match (true) {
            ! $vendor->allows_scheduled_orders => 'This store does not take scheduled orders.',
            $at->lt($earliest) => 'Choose a time at least '.$cfg['min_schedule_lead_minutes'].' minutes from now.',
            $at->gt($latest) => 'Choose a time within '.$cfg['max_schedule_days'].' days.',
            ! $this->schedule->scheduledOpen($vendor, $at) => 'The store is closed at that time.',
            default => null,
        };
        if ($message !== null) {
            $this->fail('schedule_invalid', ['scheduled_for' => [$message]]);
        }
    }

    /** @return array{0: list<Product>, 1: list<PricedLine>} */
    private function priceLines(Vendor $vendor, QuoteInput $input): array
    {
        $byUuid = $vendor->products()
            ->whereIn('uuid', array_column($input->items, 'product_id'))
            ->with('optionGroups.options', 'categories')
            ->get()
            ->keyBy('uuid');

        $wanted = [];
        foreach ($input->items as $item) {
            $wanted[$item['product_id']] = ($wanted[$item['product_id']] ?? 0) + $item['quantity'];
        }

        $products = [];
        $priced = [];
        foreach ($input->items as $i => $item) {
            /** @var Product|null $product */
            $product = $byUuid->get($item['product_id']);
            if ($product === null || ! $product->is_active) {
                $this->fail('product_unavailable', ["items.{$i}.product_id" => ['This item is no longer available.']]);
            }
            if ($product->requires_prescription) {
                // The prescription upload and pharmacy review flow is not built yet.
                $this->fail('prescription_required', ["items.{$i}.product_id" => ['This item needs a prescription.']]);
            }
            if ($input->fulfilment === Fulfilment::Delivery && ! $product->is_deliverable) {
                $this->fail('product_not_deliverable', ["items.{$i}.product_id" => ['This item is for pickup only.']]);
            }
            if ($product->stock_qty !== null && $wanted[$product->uuid] > $product->stock_qty) {
                $this->fail('out_of_stock', ["items.{$i}.quantity" => ['Only '.$product->stock_qty.' left.']], meta: ['product_id' => $product->uuid, 'available' => $product->stock_qty]);
            }

            $products[] = $product;
            $priced[] = $this->lines->price($product, $item['quantity'], $item['option_ids'], "items.{$i}.option_ids");
        }

        return [$products, $priced];
    }

    /** @return array{0: Address|null, 1: DeliveryQuote|null} */
    private function fulfilment(User $customer, Vendor $vendor, QuoteInput $input): array
    {
        if ($input->fulfilment === Fulfilment::Pickup) {
            if (! $vendor->accepts_pickup) {
                $this->fail('fulfilment_unavailable', ['fulfilment' => ['This store does not offer pickup.']]);
            }

            return [null, null];
        }

        $address = $customer->addresses()->where('uuid', (string) $input->addressId)->first()
            ?? $this->fail('validation_failed', ['address_id' => ['Choose one of your saved addresses.']], 400);

        $delivery = $this->deliveries->quote($vendor, (float) $address->lat, (float) $address->lng);
        if (! $delivery->deliverable) {
            $this->fail('address_not_deliverable', ['address_id' => ['This store does not deliver to that address.']], meta: ['reason' => $delivery->reason]);
        }

        return [$address, $delivery];
    }

    private function checkLimits(Vendor $vendor, int $subtotal): void
    {
        $min = max($vendor->min_order_kobo, (int) config('wegodeliva.orders.min_subtotal_kobo'));
        if ($subtotal < $min) {
            $this->fail('below_minimum_order', [], meta: ['minimum' => Money::of($min)]);
        }

        $maxes = array_filter([$vendor->max_order_kobo, config('wegodeliva.orders.max_subtotal_kobo')], fn ($v) => $v !== null);
        if ($maxes !== [] && $subtotal > min($maxes)) {
            $this->fail('above_maximum_order', [], meta: ['maximum' => Money::of((int) min($maxes))]);
        }
    }

    /** @param  list<PricedLine>  $lines */
    private function coupon(User $customer, Vendor $vendor, string $code, array $lines, ?int $deliveryFee): CouponDiscount
    {
        $coupon = Coupon::query()->where('code', Coupon::normaliseCode($code))->with('vendors', 'products')->first();
        if ($coupon === null) {
            throw new ApiException('coupon_invalid', 422, fields: ['coupon_code' => ['We do not recognise this code.']], meta: ['reason' => 'not_found']);
        }

        return $this->coupons->apply($coupon, new CouponContext(
            vendorId: $vendor->id,
            vendorTypeId: $vendor->vendor_type_id,
            lines: $lines,
            deliveryFeeKobo: $deliveryFee,
            isFirstOrder: ! Order::query()->where('customer_id', $customer->id)->where('status', '!=', OrderStatus::Cancelled->value)->exists(),
            usesTotal: CouponRedemption::query()->counted()->where('coupon_id', $coupon->id)->count(),
            usesByCustomer: CouponRedemption::query()->counted()->where('coupon_id', $coupon->id)->where('user_id', $customer->id)->count(),
            now: now(),
        ));
    }

    /** @return array<string, string|null> method => null if available, else why not */
    private function paymentOptions(User $customer, int $total): array
    {
        $cfg = config('wegodeliva.payments');
        $options = [];
        foreach (PaymentMethod::cases() as $method) {
            $options[$method->value] = match (true) {
                ! $cfg[$method->value] => 'disabled',
                $method === PaymentMethod::Wallet && $this->wallets->wallet($customer)->balance_kobo < $total => 'insufficient_balance',
                $method === PaymentMethod::PayOnDelivery && $total > (int) $cfg['pay_on_delivery_max_kobo'] => 'above_limit',
                default => null,
            };
        }

        return $options;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, array $fields = [], int $status = 422, array $meta = []): never
    {
        throw new ApiException($code, $status, $fields, $meta);
    }
}

<?php

namespace App\Services\Orders;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalogue\ZoneLocator;
use App\Services\Pricing\LinePricer;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricingSettings;
use App\Services\Pricing\ServicePricer;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Validates and prices a service booking (spec 04 §4.8, spec 05 §5.9): the service and its
 * provider are active, the units and location suit the service, the slot is inside the
 * provider's hours with room left, and the price is the service line plus any call-out fee,
 * with coupons, the provider's fees and VAT as for goods. Runs for the quote and again at
 * placement.
 */
final class BookingQuoteBuilder
{
    public function __construct(
        private readonly LinePricer $lines,
        private readonly ServicePricer $pricer,
        private readonly PriceCalculator $calculator,
        private readonly CouponLookup $coupons,
        private readonly BookingSlots $slots,
        private readonly ZoneLocator $zones,
        private readonly PricingSettings $settings,
        private readonly PaymentOptions $payments,
    ) {}

    public function build(User $customer, BookingInput $input): BuiltQuote
    {
        $service = $this->service($input->serviceId);
        $vendor = $service->vendor;
        $this->checkUnits($service, $input->units);
        $address = $this->location($customer, $service, $input);

        $cfg = config('wegodeliva.bookings');
        $start = $input->startsAt;
        $end = $this->pricer->endsAt($service, $start, $input->units, (int) $cfg['default_duration_minutes']);
        $message = match (true) {
            $start->lt(now()->addMinutes((int) $cfg['min_lead_minutes'])) => 'Choose a time at least '.$cfg['min_lead_minutes'].' minutes from now.',
            $start->gt(now()->addDays((int) $cfg['max_advance_days'])) => 'Choose a time within '.$cfg['max_advance_days'].' days.',
            ! $this->slots->fits($service, $start, $end) => 'The provider is closed at that time.',
            default => null,
        };
        if ($message !== null) {
            throw new ApiException('schedule_invalid', 422, ['starts_at' => [$message]]);
        }
        if (! $this->slots->hasRoom($service, $start, $end)) {
            throw new ApiException('slot_unavailable', 409, ['starts_at' => ['This time is already booked. Choose another.']]);
        }

        $line = $this->pricer->line($service, $input->units, $this->lines->price($service, 1, $input->optionIds, 'option_ids'));
        $callout = $address === null ? null : $this->pricer->calloutFee($service, Geo::distanceMeters((float) $vendor->lat, (float) $vendor->lng, (float) $address->lat, (float) $address->lng));
        $coupon = $input->couponCode === null ? null : $this->coupons->apply($customer, $vendor, $input->couponCode, [$line], $callout);
        $price = $this->calculator->calculate([$line], $vendor->vat_registered, $callout, $coupon, $vendor->fees);

        return new BuiltQuote(
            $input,
            $vendor,
            $price,
            [$service],
            $address,
            null,
            $this->payments->for($customer, $price->totalKobo),
            booking: new BookingQuote($service, $input->units, $start, $end, $input->location),
        );
    }

    private function service(string $id): Product
    {
        /** @var Product|null $service */
        $service = Product::query()
            ->where('uuid', $id)
            ->where('is_active', true)
            ->whereNotNull('pricing_unit')
            ->whereHas('vendor', fn (Builder $q) => $q->active()->whereHas('vendorType', fn (Builder $t) => $t->where('slug', Order::TYPE_SERVICE)))
            ->with('vendor.hours', 'vendor.fees', 'vendor.vendorType', 'vendor.zones', 'optionGroups.options', 'categories')
            ->first();

        return $service ?? throw new ApiException('product_unavailable', 422, ['service_id' => ['This service is not available.']]);
    }

    private function checkUnits(Product $service, int $units): void
    {
        [$min, $max] = $service->pricing_unit === 'fixed' ? [1, 1] : [$service->min_units ?? 1, $service->max_units ?? PHP_INT_MAX];
        if ($units < $min || $units > $max) {
            throw new ApiException('validation_failed', 400, ['units' => [$max === PHP_INT_MAX ? "Book at least {$min}." : "Book between {$min} and {$max}."]]);
        }
    }

    /** The customer's address for work at their place (it must be in the provider's area), else null. */
    private function location(User $customer, Product $service, BookingInput $input): ?Address
    {
        $mode = $service->location_mode ?? 'at_provider';
        if ($mode !== 'either' && $mode !== $input->location) {
            throw new ApiException('fulfilment_unavailable', 422, ['location' => [$mode === 'at_customer' ? 'This provider comes to you.' : 'This service happens at the provider\'s place.']]);
        }
        if (! $input->atCustomer()) {
            return null;
        }

        $address = $customer->addresses()->where('uuid', (string) $input->addressId)->first()
            ?? throw new ApiException('validation_failed', 400, ['address_id' => ['Choose one of your saved addresses.']]);

        $vendor = $service->vendor;
        $zone = $this->zones->zoneAt((float) $address->lat, (float) $address->lng);
        $distance = Geo::distanceMeters((float) $vendor->lat, (float) $vendor->lng, (float) $address->lat, (float) $address->lng);
        $inZone = $zone !== null && $vendor->zones->contains('id', $zone->id);
        if (! $inZone && ($distance > $vendor->delivery_radius_m || $distance > $this->settings->maxDeliveryDistanceM)) {
            throw new ApiException('address_not_deliverable', 422, ['address_id' => ['This provider does not cover that address.']], ['reason' => 'out_of_range']);
        }

        return $address;
    }
}

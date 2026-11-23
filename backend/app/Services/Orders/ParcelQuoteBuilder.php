<?php

namespace App\Services\Orders;

use App\Enums\OpenState;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PackageType;
use App\Models\ParcelRate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Catalogue\VendorSchedule;
use App\Services\Catalogue\ZoneLocator;
use App\Services\Pricing\ParcelPricer;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PricingSettings;
use App\Support\Geo;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prices parcels (spec 05 §5.3, spec 09 §9.6). `options` lists the operators that serve every
 * stop with their price; `build` validates and prices the parcel with the chosen operator, for
 * the quote and again at placement. Parcels carry no VAT, coupon or tip: the whole price is the
 * operator's, less commission (owner decisions 2026-11-23).
 */
final class ParcelQuoteBuilder
{
    public function __construct(
        private readonly ParcelPricer $pricer,
        private readonly PricingSettings $settings,
        private readonly ZoneLocator $zones,
        private readonly VendorSchedule $schedule,
        private readonly PaymentOptions $payments,
    ) {}

    /** @return list<array<string, mixed>> operators serving every stop, cheapest first */
    public function options(ParcelInput $input): array
    {
        $type = $this->packageType($input);
        $rates = $this->rates($type)->get();

        $options = [];
        foreach ($rates as $rate) {
            if ($this->unservedStop($rate->vendor, $input) !== null) {
                continue;
            }
            $blocker = $this->blocker($rate, $input);
            $price = $blocker === null ? $this->pricer->price($rate, $input->points(), $input->weightG) : null;
            $options[] = [
                'operator' => ['id' => $rate->vendor->uuid, 'name' => $rate->vendor->name, 'logo_path' => $rate->vendor->logo_path, 'rating' => (float) $rate->vendor->rating_avg],
                'available' => $blocker === null,
                'reason' => $blocker,
                'price' => $price === null ? null : Money::of($price->subtotalKobo),
                'route_m' => $price?->routeM,
            ];
        }
        usort($options, fn (array $a, array $b) => [! $a['available'], $a['price']['amount_kobo'] ?? 0] <=> [! $b['available'], $b['price']['amount_kobo'] ?? 0]);

        return $options;
    }

    public function build(User $customer, ParcelInput $input): BuiltQuote
    {
        $type = $this->packageType($input);
        /** @var ParcelRate|null $rate */
        $rate = $this->rates($type)->whereHas('vendor', fn (Builder $q) => $q->where('uuid', (string) $input->operatorId))->first();
        if ($rate === null) {
            throw new ApiException('vendor_unavailable', 422, ['operator_id' => ['This operator does not carry this package type.']]);
        }

        $unserved = $this->unservedStop($rate->vendor, $input);
        if ($unserved !== null) {
            throw new ApiException('address_not_deliverable', 422, ["stops.{$unserved}" => ['This operator does not cover this address.']], ['reason' => 'out_of_range']);
        }
        $this->failOn($rate, $this->blocker($rate, $input));

        $parcel = $this->pricer->price($rate, $input->points(), $input->weightG);
        $price = new PriceBreakdown(
            lines: [],
            lineTaxes: [],
            subtotalKobo: $parcel->subtotalKobo,
            discountKobo: 0,
            discountFundedBy: null,
            deliveryFeeKobo: 0,
            deliveryDiscountKobo: 0,
            fees: [],
            serviceFeeKobo: 0,
            taxKobo: 0,
            taxRateBp: 0,
            tipKobo: 0,
            totalKobo: $parcel->subtotalKobo,
            coupon: null,
        );

        return new BuiltQuote(
            $input,
            $rate->vendor,
            $price,
            [],
            null,
            null,
            $this->payments->for($customer, $price->totalKobo, $input->recipientPays()),
            new ParcelQuote($input, $type, $rate, $parcel),
        );
    }

    private function packageType(ParcelInput $input): PackageType
    {
        return PackageType::query()->where('uuid', $input->packageTypeId)->where('is_active', true)->first()
            ?? throw new ApiException('package_type_unavailable', 422, ['package_type_id' => ['Choose another package type.']]);
    }

    /** @return Builder<ParcelRate> active rates of active parcel operators for the package type */
    private function rates(PackageType $type): Builder
    {
        return ParcelRate::query()
            ->where('package_type_id', $type->id)
            ->where('is_active', true)
            ->whereHas('vendor', fn (Builder $q) => $q->active()->whereHas('vendorType', fn (Builder $t) => $t->where('slug', Order::TYPE_PARCEL)))
            ->with('vendor.hours', 'vendor.zones', 'vendor.vendorType')
            ->orderBy('id');
    }

    /**
     * Index of the first stop the operator does not cover, or null. A stop is covered when it
     * lies in one of the operator's zones or, as for deliveries, within its radius.
     */
    private function unservedStop(Vendor $operator, ParcelInput $input): ?int
    {
        foreach ($input->stops as $i => $stop) {
            $zone = $this->zones->zoneAt($stop['lat'], $stop['lng']);
            if ($zone !== null && $operator->zones->contains('id', $zone->id)) {
                continue;
            }
            $distance = Geo::distanceMeters((float) $operator->lat, (float) $operator->lng, $stop['lat'], $stop['lng']);
            if ($distance > $operator->delivery_radius_m || $distance > $this->settings->maxDeliveryDistanceM) {
                return $i;
            }
        }

        return null;
    }

    /** Why this operator cannot take this parcel, or null. */
    private function blocker(ParcelRate $rate, ParcelInput $input): ?string
    {
        $operator = $rate->vendor;
        $at = $input->scheduledFor;

        return match (true) {
            $rate->needsWeight() && $input->weightG === null => 'weight_required',
            $rate->requires_dimensions && ! $input->hasDimensions() => 'dimensions_required',
            $at === null => $operator->openState() === OpenState::Open ? null : 'closed',
            ! $operator->allows_scheduled_orders,
            $at->lt(now()->addMinutes((int) config('wegodeliva.orders.min_schedule_lead_minutes'))),
            $at->gt(now()->addDays($rate->max_advance_booking_days)),
            ! $this->schedule->scheduledOpen($operator, $at) => 'schedule_unavailable',
            default => null,
        };
    }

    private function failOn(ParcelRate $rate, ?string $blocker): void
    {
        match ($blocker) {
            'weight_required' => throw new ApiException('validation_failed', 400, ['weight_g' => ['This operator needs the weight.']]),
            'dimensions_required' => throw new ApiException('validation_failed', 400, ['dimensions' => ['This operator needs the length, width and height.']]),
            'closed' => throw new ApiException('vendor_closed', 422, meta: ['open_state' => $rate->vendor->openState()->value, 'can_schedule' => $rate->vendor->allows_scheduled_orders]),
            'schedule_unavailable' => throw new ApiException('schedule_invalid', 422, ['scheduled_for' => ['This operator cannot collect at that time.']], ['max_advance_days' => $rate->max_advance_booking_days]),
            default => null,
        };
    }
}

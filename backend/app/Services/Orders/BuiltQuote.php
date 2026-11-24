<?php

namespace App\Services\Orders;

use App\Enums\Fulfilment;
use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Pricing\DeliveryQuote;
use App\Services\Pricing\PriceBreakdown;
use App\Support\Money;

/** A fully validated and priced cart, or a parcel (then `parcel` is set and there are no products). */
final readonly class BuiltQuote
{
    /**
     * @param  list<Product>  $products  same order as the price lines
     * @param  array<string, string|null>  $paymentOptions  every payment method => null when available, else the reason it is not
     */
    public function __construct(
        public QuoteInput|ParcelInput|BookingInput $input,
        public Vendor $vendor,
        public PriceBreakdown $price,
        public array $products,
        public ?Address $address,
        public ?DeliveryQuote $delivery,
        public array $paymentOptions,
        public ?ParcelQuote $parcel = null,
        public ?BookingQuote $booking = null,
    ) {}

    /** Goods follow the cart; parcels are delivered; services at the provider's place count as pickup. */
    public function fulfilment(): Fulfilment
    {
        return match (true) {
            $this->input instanceof QuoteInput => $this->input->fulfilment,
            $this->input instanceof BookingInput => $this->input->atCustomer() ? Fulfilment::Delivery : Fulfilment::Pickup,
            default => Fulfilment::Delivery,
        };
    }

    public function fingerprint(): string
    {
        $priced = match (true) {
            $this->parcel !== null => ['price' => $this->price->toArray(), 'parcel' => $this->parcel->toArray()],
            $this->booking !== null => ['price' => $this->price->toArray(), 'booking' => $this->booking->toArray()],
            default => $this->price->toArray(),
        };

        return hash('sha256', (string) json_encode($priced));
    }

    public function unavailableReason(PaymentMethod $method): ?string
    {
        return $this->paymentOptions[$method->value];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $scheduled = $this->input instanceof BookingInput ? null : $this->input->scheduledFor;

        $extra = match (true) {
            $this->parcel !== null => ['parcel' => $this->parcel->toArray()],
            $this->booking !== null => ['booking' => $this->booking->toArray()],
            default => [],
        };

        return [
            'vendor' => ['id' => $this->vendor->uuid, 'name' => $this->vendor->name, 'slug' => $this->vendor->slug],
            'fulfilment' => $this->fulfilment()->value,
            'address_id' => $this->address?->uuid,
            'scheduled_for' => $scheduled?->toIso8601String(),
            'delivery' => $this->delivery === null ? null : [
                'fee' => Money::of($this->delivery->feeKobo),
                'distance_m' => $this->delivery->distanceM,
                'eta_minutes' => ['min' => $this->delivery->etaMinMinutes, 'max' => $this->delivery->etaMaxMinutes],
            ],
            'price' => $this->price->toArray(),
            'payment_options' => array_map(
                fn (string $method) => ['method' => $method, 'available' => $this->paymentOptions[$method] === null, 'reason' => $this->paymentOptions[$method]],
                array_keys($this->paymentOptions),
            ),
        ] + $extra;
    }
}

<?php

namespace App\Services\Orders;

use Illuminate\Support\Carbon;

/** A service booking the customer asked to be priced (spec 09 §9.9 "Book a service"). */
final readonly class BookingInput
{
    public const KIND = 'booking';

    /** @param  list<string>  $optionIds */
    public function __construct(
        public string $serviceId,
        public int $units,
        public array $optionIds,
        public Carbon $startsAt,
        public string $location,
        public ?string $addressId = null,
        public ?string $couponCode = null,
    ) {}

    /** @param  array<string, mixed>  $data  validated request data or a stored quote's input */
    public static function fromArray(array $data): self
    {
        /** @var array<int|string, string> $options */
        $options = $data['option_ids'] ?? [];

        return new self(
            serviceId: (string) $data['service_id'],
            units: (int) ($data['units'] ?? 1),
            optionIds: array_values(array_map('strval', $options)),
            startsAt: Carbon::parse((string) $data['starts_at'])->utc(),
            location: (string) $data['location'],
            addressId: isset($data['address_id']) ? (string) $data['address_id'] : null,
            couponCode: isset($data['coupon_code']) && $data['coupon_code'] !== '' ? (string) $data['coupon_code'] : null,
        );
    }

    public function atCustomer(): bool
    {
        return $this->location === 'at_customer';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'service_id' => $this->serviceId,
            'units' => $this->units,
            'option_ids' => $this->optionIds,
            'starts_at' => $this->startsAt->toIso8601String(),
            'location' => $this->location,
            'address_id' => $this->addressId,
            'coupon_code' => $this->couponCode,
        ];
    }
}

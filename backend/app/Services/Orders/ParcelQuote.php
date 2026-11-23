<?php

namespace App\Services\Orders;

use App\Models\PackageType;
use App\Models\ParcelRate;
use App\Services\Pricing\ParcelPrice;

/** The parcel part of a built quote: what is sent, along which stops, at which rate. */
final readonly class ParcelQuote
{
    public function __construct(
        public ParcelInput $input,
        public PackageType $packageType,
        public ParcelRate $rate,
        public ParcelPrice $price,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package_type' => ['id' => $this->packageType->uuid, 'name' => $this->packageType->name],
            'payer' => $this->input->payer,
            'weight_g' => $this->input->weightG,
            'dimensions_cm' => $this->input->hasDimensions() ? ['length' => $this->input->lengthCm, 'width' => $this->input->widthCm, 'height' => $this->input->heightCm] : null,
            'stops' => array_map(fn (array $s, int $i) => ['sequence' => $i + 1, 'kind' => $i === 0 ? 'pickup' : 'dropoff'] + $s, $this->input->stops, array_keys($this->input->stops)),
            'pricing' => $this->price->toArray(),
        ];
    }
}

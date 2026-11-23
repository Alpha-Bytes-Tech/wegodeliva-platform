<?php

namespace App\Services\Orders;

use Illuminate\Support\Carbon;

/**
 * A parcel the customer asked to be priced (spec 09 §9.6 "New parcel"). The first stop is the
 * pickup, the rest are drop-offs. Stored with the quote and re-priced at placement.
 */
final readonly class ParcelInput
{
    public const KIND = 'parcel';

    /**
     * @param  list<array{line1: string, landmark: string|null, area: string|null, lat: float, lng: float, contact_name: string, contact_phone: string, note: string|null}>  $stops
     */
    public function __construct(
        public string $packageTypeId,
        public array $stops,
        public string $payer,
        public ?string $operatorId = null,
        public ?int $weightG = null,
        public ?int $lengthCm = null,
        public ?int $widthCm = null,
        public ?int $heightCm = null,
        public ?Carbon $scheduledFor = null,
    ) {}

    /** @param  array<string, mixed>  $data  validated request data or a stored quote's input */
    public static function fromArray(array $data): self
    {
        /** @var array<int|string, array<string, mixed>> $stops */
        $stops = $data['stops'];
        $int = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;
        $text = fn (array $stop, string $key): ?string => isset($stop[$key]) && $stop[$key] !== '' ? (string) $stop[$key] : null;

        return new self(
            packageTypeId: (string) $data['package_type_id'],
            stops: array_map(fn (array $s) => [
                'line1' => (string) $s['line1'],
                'landmark' => $text($s, 'landmark'),
                'area' => $text($s, 'area'),
                'lat' => (float) $s['lat'],
                'lng' => (float) $s['lng'],
                'contact_name' => (string) $s['contact_name'],
                'contact_phone' => (string) $s['contact_phone'],
                'note' => $text($s, 'note'),
            ], array_values($stops)),
            payer: (string) $data['payer'],
            operatorId: isset($data['operator_id']) ? (string) $data['operator_id'] : null,
            weightG: $int('weight_g'),
            lengthCm: $int('length_cm'),
            widthCm: $int('width_cm'),
            heightCm: $int('height_cm'),
            scheduledFor: isset($data['scheduled_for']) ? Carbon::parse((string) $data['scheduled_for'])->utc() : null,
        );
    }

    public function hasDimensions(): bool
    {
        return $this->lengthCm !== null && $this->widthCm !== null && $this->heightCm !== null;
    }

    public function recipientPays(): bool
    {
        return $this->payer === 'recipient';
    }

    /** @return list<array{lat: float, lng: float}> */
    public function points(): array
    {
        return array_map(fn (array $s) => ['lat' => $s['lat'], 'lng' => $s['lng']], $this->stops);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'package_type_id' => $this->packageTypeId,
            'operator_id' => $this->operatorId,
            'stops' => $this->stops,
            'payer' => $this->payer,
            'weight_g' => $this->weightG,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
            'scheduled_for' => $this->scheduledFor?->toIso8601String(),
        ];
    }
}

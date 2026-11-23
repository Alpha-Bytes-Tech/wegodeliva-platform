<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parcel operator's price for one package type (spec 03 §3.4, spec 05 §5.3).
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property int $package_type_id
 * @property int $base_price_kobo
 * @property int $distance_rate_kobo
 * @property bool $distance_per_km
 * @property int $size_rate_kobo
 * @property bool $size_per_kg
 * @property int $extra_stop_fee_kobo
 * @property int $max_advance_booking_days
 * @property bool $auto_dispatch
 * @property bool $requires_dimensions
 * @property bool $is_active
 */
class ParcelRate extends Model
{
    use HasUuids;

    protected $fillable = [
        'base_price_kobo', 'distance_rate_kobo', 'distance_per_km', 'size_rate_kobo', 'size_per_kg',
        'extra_stop_fee_kobo', 'max_advance_booking_days', 'auto_dispatch', 'requires_dimensions', 'is_active',
    ];

    protected $hidden = ['id', 'vendor_id', 'package_type_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'base_price_kobo' => 'integer',
            'distance_rate_kobo' => 'integer',
            'distance_per_km' => 'boolean',
            'size_rate_kobo' => 'integer',
            'size_per_kg' => 'boolean',
            'extra_stop_fee_kobo' => 'integer',
            'max_advance_booking_days' => 'integer',
            'auto_dispatch' => 'boolean',
            'requires_dimensions' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<PackageType, $this> */
    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    /** Weight is needed when the price depends on it or the operator asks for the size. */
    public function needsWeight(): bool
    {
        return $this->size_per_kg || $this->requires_dimensions;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery zone. The `polygon` column (PostGIS geography) is written by the admin zone editor
 * in Phase 6 through raw SQL, so it is not mass-assignable here.
 *
 * @property int $id
 * @property string $uuid
 * @property int $city_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property bool $is_campus
 * @property int|null $default_delivery_fee_kobo
 * @property int $rider_search_radius_m
 * @property string|null $notes
 */
class Zone extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'is_active', 'is_campus', 'default_delivery_fee_kobo',
        'rider_search_radius_m', 'notes',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_campus' => 'boolean',
            'default_delivery_fee_kobo' => 'integer',
            'rider_search_radius_m' => 'integer',
        ];
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}

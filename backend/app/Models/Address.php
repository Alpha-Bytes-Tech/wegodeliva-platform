<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's saved address (spec 03 §3.2). Orders keep their own snapshot, so editing or
 * deleting an address never changes a past order.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $label
 * @property string $line1
 * @property string|null $landmark
 * @property string|null $area
 * @property string $lat
 * @property string $lng
 * @property int|null $city_id
 * @property int|null $zone_id
 * @property string|null $campus_building
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property bool $is_default
 */
class Address extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'label', 'line1', 'landmark', 'area', 'lat', 'lng', 'campus_building', 'contact_name', 'contact_phone', 'is_default',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_default' => false];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}

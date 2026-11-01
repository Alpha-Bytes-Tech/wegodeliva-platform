<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $state
 * @property bool $is_active
 * @property string|null $centre_lat
 * @property string|null $centre_lng
 */
class City extends Model
{
    protected $fillable = ['name', 'slug', 'state', 'is_active', 'centre_lat', 'centre_lng'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Zone, $this> */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of choices attached to products, e.g. "Choose your drink" (spec 03 §3.3).
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property string $name
 * @property bool $is_required
 * @property int $min_select
 * @property int $max_select
 * @property int $sort_order
 * @property bool $is_active
 */
class OptionGroup extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'is_required', 'min_select', 'max_select', 'sort_order', 'is_active'];

    /** Same defaults as the database, so new records serialise correctly. @var array<string, mixed> */
    protected $attributes = ['is_required' => false, 'min_select' => 0, 'max_select' => 1, 'sort_order' => 0, 'is_active' => true];

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
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<Option, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order')->orderBy('id');
    }
}

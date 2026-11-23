<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice in an option group. Its price is added per unit (spec 05 §5.1).
 *
 * @property int $id
 * @property string $uuid
 * @property int $option_group_id
 * @property string $name
 * @property string|null $description
 * @property int $price_kobo
 * @property bool $per_unit service options: charged per booked unit, else once per booking
 * @property int $sort_order
 * @property bool $is_active
 */
class Option extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'price_kobo', 'per_unit', 'sort_order', 'is_active'];

    /** Same defaults as the database, so new records serialise correctly. @var array<string, mixed> */
    protected $attributes = ['price_kobo' => 0, 'per_unit' => true, 'sort_order' => 0, 'is_active' => true];

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
        return ['price_kobo' => 'integer', 'per_unit' => 'boolean', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<OptionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}

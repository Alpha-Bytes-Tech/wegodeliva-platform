<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A service fee added to orders of the vendors it is assigned to (spec 05 §5.5), e.g.
 * "Service charge" or "Packaging".
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property DiscountType $type
 * @property int $value basis points (percent) or kobo (fixed)
 * @property Party $beneficiary
 * @property bool $is_active
 */
class Fee extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'type', 'value', 'beneficiary', 'is_active'];

    protected $hidden = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

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
            'type' => DiscountType::class,
            'value' => 'integer',
            'beneficiary' => Party::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Vendor, $this> */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class);
    }
}

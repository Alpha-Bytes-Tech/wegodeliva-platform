<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A vendor's own menu section, e.g. "Rice dishes" (spec 03 §3.3).
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class VendorMenu extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'sort_order', 'is_active'];

    /** Same defaults as the database, so new records serialise correctly. @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0, 'is_active' => true];

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
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_vendor_menu');
    }
}

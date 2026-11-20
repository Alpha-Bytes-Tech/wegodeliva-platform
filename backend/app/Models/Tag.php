<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product tag such as "Spicy" or "Halal", per vendor type (spec 03 §3.3).
 *
 * @property int $id
 * @property int $vendor_type_id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 */
class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order'];

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }
}

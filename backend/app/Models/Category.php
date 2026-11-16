<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_type_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $vat_treatment
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'image_path', 'color', 'vat_treatment', 'sort_order', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }
}

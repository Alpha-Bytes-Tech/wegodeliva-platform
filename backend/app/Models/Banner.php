<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A home-carousel banner or onboarding slide, optionally for one vendor type and time window.
 *
 * @property int $id
 * @property string $uuid
 * @property string $placement home | onboarding
 * @property string $title
 * @property string|null $subtitle
 * @property string $image_path
 * @property string|null $link
 * @property int|null $vendor_type_id
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class Banner extends Model
{
    use HasUuids;

    protected $fillable = ['placement', 'title', 'subtitle', 'image_path', 'link', 'vendor_type_id', 'sort_order', 'is_active', 'starts_at', 'ends_at'];

    protected $hidden = ['id', 'vendor_type_id'];

    /** @var array<string, mixed> */
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
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * Active and inside its time window now.
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }
}

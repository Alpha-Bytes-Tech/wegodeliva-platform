<?php

namespace App\Models;

use App\Enums\CouponAppliesTo;
use App\Enums\CouponScope;
use App\Enums\DiscountType;
use App\Enums\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code (spec 03 §3.6, rules in spec 05 §5.4).
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string|null $description
 * @property DiscountType $discount_type
 * @property int $value basis points (percent) or kobo (fixed)
 * @property int|null $max_discount_kobo
 * @property int $min_order_kobo
 * @property CouponAppliesTo $applies_to
 * @property CouponScope $scope
 * @property int|null $vendor_type_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_uses_total
 * @property int|null $max_uses_per_user
 * @property bool $first_order_only
 * @property Party $funded_by
 * @property bool $is_active
 * @property int|null $created_by
 */
class Coupon extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'description', 'discount_type', 'value', 'max_discount_kobo', 'min_order_kobo', 'applies_to',
        'scope', 'starts_at', 'ends_at', 'max_uses_total', 'max_uses_per_user', 'first_order_only',
        'funded_by', 'is_active',
    ];

    protected $hidden = ['id', 'vendor_type_id', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'min_order_kobo' => 0,
        'applies_to' => 'order_subtotal',
        'scope' => 'all',
        'first_order_only' => false,
        'funded_by' => 'vendor',
        'is_active' => true,
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'value' => 'integer',
            'max_discount_kobo' => 'integer',
            'min_order_kobo' => 'integer',
            'applies_to' => CouponAppliesTo::class,
            'scope' => CouponScope::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses_total' => 'integer',
            'max_uses_per_user' => 'integer',
            'first_order_only' => 'boolean',
            'funded_by' => Party::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    /** @return BelongsToMany<Vendor, $this> */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}

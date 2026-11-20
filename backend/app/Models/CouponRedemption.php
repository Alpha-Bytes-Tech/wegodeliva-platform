<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One use of a coupon on an order. Released uses no longer count against the limits.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $user_id
 * @property int|null $order_id
 * @property int $discount_kobo
 * @property Carbon|null $released_at
 */
class CouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'user_id', 'order_id', 'discount_kobo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discount_kobo' => 'integer', 'released_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}

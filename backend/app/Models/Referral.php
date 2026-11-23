<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One referred customer (spec 06 §6.8). The referrer is rewarded once, on the referred
 * customer's first delivered order.
 *
 * @property int $id
 * @property string $uuid
 * @property int $referrer_user_id
 * @property int $referred_user_id
 * @property string $status pending | rewarded | void
 * @property int $reward_kobo
 * @property int|null $qualifying_order_id
 * @property Carbon|null $rewarded_at
 * @property string|null $void_reason
 * @property Carbon $created_at
 * @property-read User|null $referred
 */
class Referral extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'referrer_user_id', 'referred_user_id', 'qualifying_order_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'reward_kobo' => 0];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reward_kobo' => 'integer', 'rewarded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}

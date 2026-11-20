<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's closed-loop wallet (spec 06 §6.4). Change the balance only through WalletLedger.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $balance_kobo
 * @property string $currency
 * @property int $kyc_tier
 */
class Wallet extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['balance_kobo' => 0, 'currency' => 'NGN', 'kyc_tier' => 1];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['balance_kobo' => 'integer', 'kyc_tier' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}

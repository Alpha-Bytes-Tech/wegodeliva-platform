<?php

namespace App\Models;

use App\Enums\EarningsOwner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Earnings of one vendor or rider (spec 05 §5.8). Change it only through EarningsLedger.
 *
 * @property int $id
 * @property string $uuid
 * @property EarningsOwner $owner_type
 * @property int $owner_id
 * @property int $balance_kobo
 * @property int $commission_due_kobo
 * @property int $commission_vat_due_kobo
 * @property int $cash_owed_kobo
 * @property int $held_kobo
 */
class EarningsAccount extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'owner_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['balance_kobo' => 0, 'commission_due_kobo' => 0, 'commission_vat_due_kobo' => 0, 'cash_owed_kobo' => 0, 'held_kobo' => 0];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_type' => EarningsOwner::class,
            'balance_kobo' => 'integer',
            'commission_due_kobo' => 'integer',
            'commission_vat_due_kobo' => 'integer',
            'cash_owed_kobo' => 'integer',
            'held_kobo' => 'integer',
        ];
    }

    /**
     * Available to withdraw = gross − commission due − VAT on commission − cash owed − pending
     * payouts (spec 05 §5.8). Never shown below zero.
     */
    public function availableKobo(): int
    {
        return max(0, $this->balance_kobo - $this->commission_due_kobo - $this->commission_vat_due_kobo - $this->cash_owed_kobo - $this->held_kobo);
    }

    /** @return HasMany<EarningsEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(EarningsEntry::class);
    }
}

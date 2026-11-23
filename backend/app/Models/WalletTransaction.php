<?php

namespace App\Models;

use App\Enums\WalletKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One wallet ledger entry. Append-only: the database refuses updates and deletes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $wallet_id
 * @property string $direction credit | debit
 * @property int $amount_kobo
 * @property int $balance_after_kobo
 * @property WalletKind $kind
 * @property string $reference
 * @property int|null $order_id
 * @property string|null $gateway
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class WalletTransaction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'wallet_id', 'created_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'balance_after_kobo' => 'integer',
            'kind' => WalletKind::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Signed change: positive for credits. */
    public function signedAmount(): int
    {
        return $this->direction === 'credit' ? $this->amount_kobo : -$this->amount_kobo;
    }
}

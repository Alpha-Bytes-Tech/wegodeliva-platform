<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A wallet top-up through a gateway (spec 06 §6.4). Credited only after verification.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $amount_kobo
 * @property PaymentGateway $gateway
 * @property string $status pending | succeeded | failed
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
class WalletTopup extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id', 'idempotency_key'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['gateway' => PaymentGateway::class, 'amount_kobo' => 'integer'];
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}

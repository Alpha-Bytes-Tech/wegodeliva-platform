<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A withdrawal of earnings to a bank account (spec 06 §6.7).
 *
 * @property int $id
 * @property string $uuid
 * @property int $earnings_account_id
 * @property int $payout_account_id
 * @property int $amount_kobo
 * @property string $status requested | approved | processing | paid | rejected | failed
 * @property PaymentGateway $gateway
 * @property string $transfer_reference
 * @property string|null $gateway_transfer_id
 * @property string|null $failure_reason
 * @property string|null $note
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $paid_at
 * @property string $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payout extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'earnings_account_id', 'payout_account_id', 'requested_by', 'reviewed_by', 'idempotency_key'];

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
        return ['gateway' => PaymentGateway::class, 'amount_kobo' => 'integer', 'reviewed_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<EarningsAccount, $this> */
    public function earningsAccount(): BelongsTo
    {
        return $this->belongsTo(EarningsAccount::class);
    }

    /** @return BelongsTo<PayoutAccount, $this> */
    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class)->withTrashed();
    }
}

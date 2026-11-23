<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentGateway;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One gateway checkout attempt for an order or a wallet top-up (spec 06 §6.2). Its status only
 * changes after server-side verification with the gateway.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $order_id
 * @property int|null $wallet_topup_id
 * @property PaymentGateway $gateway
 * @property string $reference
 * @property string|null $gateway_transaction_id
 * @property int $amount_kobo
 * @property string $currency
 * @property PaymentAttemptStatus $status
 * @property string|null $channel
 * @property string|null $failure_reason
 * @property string|null $checkout_url
 * @property array<string, mixed>|null $raw_verification
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 */
class Payment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id', 'order_id', 'wallet_topup_id', 'raw_verification'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'status' => PaymentAttemptStatus::class,
            'amount_kobo' => 'integer',
            'raw_verification' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<WalletTopup, $this> */
    public function topup(): BelongsTo
    {
        return $this->belongsTo(WalletTopup::class, 'wallet_topup_id');
    }
}

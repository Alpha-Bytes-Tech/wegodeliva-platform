<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A priced cart bound to one customer (spec 04 §4.4). Placement recomputes it; the stored
 * breakdown is what the customer was shown.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $vendor_id
 * @property array<string, mixed> $input
 * @property array<string, mixed> $breakdown
 * @property int $total_kobo
 * @property string $fingerprint
 * @property Carbon $expires_at
 */
class OrderQuote extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['input' => 'array', 'breakdown' => 'array', 'total_kobo' => 'integer', 'expires_at' => 'datetime'];
    }

    /** @return HasOne<Order, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'quote_id');
    }
}

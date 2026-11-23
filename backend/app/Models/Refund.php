<?php

namespace App\Models;

use App\Enums\Fault;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money returned to a customer for an order (spec 06 §6.6). Wallet refunds succeed at once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $amount_kobo
 * @property string $destination
 * @property string $status
 * @property string $reason
 * @property Fault $fault
 * @property string $reference
 * @property int|null $processed_by
 * @property Carbon $created_at
 */
class Refund extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'processed_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'fault' => Fault::class, 'created_at' => 'datetime'];
    }
}

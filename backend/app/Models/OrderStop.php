<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stop of a parcel order, in route order (spec 03 §3.5): the pickup, then the drop-offs.
 * Created only by OrderPlacer; nothing is mass-assignable.
 *
 * @property int $id
 * @property int $order_id
 * @property int $sequence
 * @property string $kind pickup | dropoff
 * @property array{line1: string, landmark: string|null, area: string|null, lat: float, lng: float} $address
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $note
 * @property string|null $verification_code
 * @property int $verify_attempts
 * @property Carbon|null $verified_at
 */
class OrderStop extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'verification_code'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'sequence' => 'integer',
            'verification_code' => 'encrypted',
            'verify_attempts' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

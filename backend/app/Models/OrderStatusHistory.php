<?php

namespace App\Models;

use App\Enums\ActorRole;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One status change of an order. Append-only.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property int|null $actor_user_id
 * @property ActorRole $actor_role
 * @property string|null $reason
 * @property Carbon $created_at
 */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'actor_role' => ActorRole::class,
            'created_at' => 'datetime',
        ];
    }
}

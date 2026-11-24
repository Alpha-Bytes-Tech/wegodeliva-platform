<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A problem a user reported (spec 09 "Support"). Nothing is mass-assignable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $order_id
 * @property string $category
 * @property string $message
 * @property list<string> $photo_paths
 * @property string $status open | in_progress | resolved | closed
 * @property string|null $resolution
 * @property int|null $handled_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupportTicket extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id', 'order_id', 'handled_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'photo_paths' => '[]'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['photo_paths' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

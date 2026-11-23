<?php

namespace App\Models;

use App\Enums\RiderType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rider's working profile (spec 03 §3.7). Location, online state and matching arrive in Phase 5.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property RiderType $rider_type
 * @property int|null $vendor_id
 * @property string $vehicle_kind
 * @property string|null $plate_number
 * @property string $status pending_approval | active | suspended
 * @property bool $is_online
 * @property int $max_active_orders
 * @property Carbon|null $approved_at
 */
class RiderProfile extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id', 'vendor_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['vehicle_kind' => 'motorbike', 'status' => 'active', 'is_online' => false, 'max_active_orders' => 1];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rider_type' => RiderType::class, 'is_online' => 'boolean', 'max_active_orders' => 'integer', 'approved_at' => 'datetime'];
    }

    public function canDeliverFor(Order $order): bool
    {
        return $this->status === 'active'
            && $this->approved_at !== null
            && ($this->rider_type !== RiderType::VendorOwned || $this->vendor_id === $order->vendor_id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}

<?php

namespace App\Models;

use App\Enums\Fault;
use App\Enums\Fulfilment;
use App\Enums\OrderStatus;
use App\Enums\Party;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An order (spec 03 §3.5). Created only by OrderPlacer; its status changes only through
 * OrderStateMachine. Nothing is mass-assignable.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property int $customer_id
 * @property int $vendor_id
 * @property int|null $rider_id
 * @property int $vendor_type_id
 * @property string $type
 * @property Fulfilment $fulfilment
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property int|null $delivery_address_id
 * @property array<string, mixed>|null $address_snapshot
 * @property int|null $distance_m
 * @property bool $campus_mode
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $release_at
 * @property string|null $note
 * @property string|null $cancel_reason
 * @property string|null $cancelled_by
 * @property Fault|null $fault
 * @property int $subtotal_kobo
 * @property int $discount_kobo
 * @property Party|null $discount_funded_by
 * @property int $delivery_fee_kobo
 * @property int $delivery_discount_kobo
 * @property int $service_fee_kobo
 * @property int $tax_kobo
 * @property int $tax_rate_bp
 * @property int $tip_kobo
 * @property int $total_kobo
 * @property int|null $coupon_id
 * @property string $handover_code
 * @property int|null $prep_time_minutes
 * @property Carbon|null $estimated_ready_at
 * @property Carbon|null $estimated_delivery_at
 * @property Carbon|null $placed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $failed_at
 * @property int|null $quote_id
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
class Order extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'customer_id', 'vendor_id', 'rider_id', 'vendor_type_id', 'delivery_address_id', 'coupon_id', 'quote_id', 'handover_code', 'idempotency_key'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fulfilment' => Fulfilment::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'fault' => Fault::class,
            'discount_funded_by' => Party::class,
            'address_snapshot' => 'array',
            'campus_mode' => 'boolean',
            'handover_code' => 'encrypted',
            'scheduled_for' => 'datetime',
            'release_at' => 'datetime',
            'estimated_ready_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
            'distance_m' => 'integer',
            'prep_time_minutes' => 'integer',
            'subtotal_kobo' => 'integer',
            'discount_kobo' => 'integer',
            'delivery_fee_kobo' => 'integer',
            'delivery_discount_kobo' => 'integer',
            'service_fee_kobo' => 'integer',
            'tax_kobo' => 'integer',
            'tax_rate_bp' => 'integer',
            'tip_kobo' => 'integer',
            'total_kobo' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderFee, $this> */
    public function fees(): HasMany
    {
        return $this->hasMany(OrderFee::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}

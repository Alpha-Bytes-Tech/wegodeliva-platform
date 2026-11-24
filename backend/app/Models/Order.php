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
 * @property int|null $package_type_id
 * @property int|null $weight_g
 * @property int|null $length_cm
 * @property int|null $width_cm
 * @property int|null $height_cm
 * @property string|null $payer sender | recipient (parcels)
 * @property int|null $units booked units (services)
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $service_location at_customer | at_provider
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $completion_confirmed_at
 * @property Carbon|null $disputed_at
 * @property string|null $dispute_reason
 * @property Carbon|null $reminded_day_at
 * @property Carbon|null $reminded_hour_at
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
 * @property Carbon|null $assigned_at
 * @property int $handover_attempts
 * @property string|null $collected_via
 * @property string|null $failure_reason
 * @property string|null $failure_photo_path
 * @property bool $customer_called
 * @property Carbon|null $vendor_alerted_at
 * @property int $rider_escalation_level
 * @property Carbon|null $stale_alerted_at
 * @property int|null $quote_id
 * @property string $idempotency_key
 * @property Carbon $created_at
 */
class Order extends Model
{
    use HasUuids, SoftDeletes;

    /** The order type of parcel deliveries (the parcels vendor type's slug). */
    public const TYPE_PARCEL = 'parcels';

    /** The order type of service bookings (the services vendor type's slug). */
    public const TYPE_SERVICE = 'services';

    protected $guarded = ['*'];

    protected $hidden = ['id', 'customer_id', 'vendor_id', 'rider_id', 'vendor_type_id', 'delivery_address_id', 'coupon_id', 'package_type_id', 'quote_id', 'handover_code', 'idempotency_key'];

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
            'assigned_at' => 'datetime',
            'vendor_alerted_at' => 'datetime',
            'stale_alerted_at' => 'datetime',
            'customer_called' => 'boolean',
            'handover_attempts' => 'integer',
            'rider_escalation_level' => 'integer',
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
            'weight_g' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'units' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'completion_confirmed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'reminded_day_at' => 'datetime',
            'reminded_hour_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** Paid amount not yet refunded. */
    public function refundableKobo(): int
    {
        if (! in_array($this->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            return 0;
        }

        return max(0, $this->total_kobo - (int) $this->refunds()->sum('amount_kobo'));
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

    public function isBooking(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function isParcel(): bool
    {
        return $this->type === self::TYPE_PARCEL;
    }

    /** @return BelongsTo<PackageType, $this> */
    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    /** @return HasMany<OrderStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(OrderStop::class)->orderBy('sequence');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}

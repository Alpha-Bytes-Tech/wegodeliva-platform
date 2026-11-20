<?php

namespace App\Models;

use App\Enums\DeliveryFeeMode;
use App\Enums\OpenState;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Services\Catalogue\VendorSchedule;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A store on the marketplace (spec 03 §3.3). Status, approval, ratings and commission are not
 * mass-assignable: they change through admin actions only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_type_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo_path
 * @property string|null $cover_path
 * @property string|null $phone
 * @property string|null $email
 * @property string $address_line
 * @property string $lat
 * @property string $lng
 * @property int|null $city_id
 * @property int|null $zone_id
 * @property VendorStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property OpenState|null $manual_state
 * @property Carbon|null $manual_state_until
 * @property bool $accepts_delivery
 * @property bool $accepts_pickup
 * @property bool $allows_scheduled_orders
 * @property bool $auto_accept_orders
 * @property bool $uses_own_riders
 * @property int $prep_time_minutes
 * @property int $delivery_time_minutes
 * @property int $min_order_kobo
 * @property int|null $max_order_kobo
 * @property DeliveryFeeMode $delivery_fee_mode
 * @property int $base_delivery_fee_kobo
 * @property int $delivery_fee_kobo
 * @property int $delivery_radius_m
 * @property int|null $commission_rate_bp
 * @property bool $vat_registered
 * @property string|null $tin
 * @property string $rating_avg
 * @property int $rating_count
 * @property Carbon|null $approved_at
 * @property Carbon $created_at
 * @property int|null $distance_m Set by the "near a point" query.
 */
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'logo_path', 'cover_path', 'phone', 'email', 'address_line', 'lat', 'lng',
        'accepts_delivery', 'accepts_pickup', 'allows_scheduled_orders', 'auto_accept_orders', 'uses_own_riders',
        'prep_time_minutes', 'delivery_time_minutes', 'min_order_kobo', 'max_order_kobo', 'delivery_fee_mode',
        'base_delivery_fee_kobo', 'delivery_fee_kobo', 'delivery_radius_m', 'vat_registered', 'tin',
    ];

    protected $hidden = ['id', 'vendor_type_id', 'city_id', 'zone_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_approval',
        'is_featured' => false,
        'sort_order' => 0,
        'accepts_delivery' => true,
        'accepts_pickup' => false,
        'allows_scheduled_orders' => false,
        'auto_accept_orders' => false,
        'uses_own_riders' => false,
        'prep_time_minutes' => 20,
        'delivery_time_minutes' => 30,
        'min_order_kobo' => 0,
        'delivery_fee_mode' => 'flat',
        'base_delivery_fee_kobo' => 0,
        'delivery_fee_kobo' => 0,
        'delivery_radius_m' => 5000,
        'vat_registered' => false,
        'rating_avg' => '0.00',
        'rating_count' => 0,
    ];

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
        return [
            'status' => VendorStatus::class,
            'manual_state' => OpenState::class,
            'manual_state_until' => 'datetime',
            'delivery_fee_mode' => DeliveryFeeMode::class,
            'is_featured' => 'boolean',
            'accepts_delivery' => 'boolean',
            'accepts_pickup' => 'boolean',
            'allows_scheduled_orders' => 'boolean',
            'auto_accept_orders' => 'boolean',
            'uses_own_riders' => 'boolean',
            'vat_registered' => 'boolean',
            'prep_time_minutes' => 'integer',
            'delivery_time_minutes' => 'integer',
            'min_order_kobo' => 'integer',
            'max_order_kobo' => 'integer',
            'base_delivery_fee_kobo' => 'integer',
            'delivery_fee_kobo' => 'integer',
            'delivery_radius_m' => 'integer',
            'commission_rate_bp' => 'integer',
            'rating_count' => 'integer',
            'sort_order' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', VendorStatus::Active->value);
    }

    /** @return BelongsTo<VendorType, $this> */
    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vendor_members')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<VendorHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(VendorHour::class)->orderBy('weekday')->orderBy('opens_at');
    }

    /** @return BelongsToMany<Zone, $this> */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'vendor_zones')->withPivot('delivery_fee_kobo')->withTimestamps();
    }

    /** @return HasMany<VendorMenu, $this> */
    public function menus(): HasMany
    {
        return $this->hasMany(VendorMenu::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<OptionGroup, $this> */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function memberRole(User $user): ?VendorMemberRole
    {
        $role = $this->members()->whereKey($user->getKey())->value('vendor_members.role');

        return is_string($role) ? VendorMemberRole::from($role) : null;
    }

    public function openState(?Carbon $at = null): OpenState
    {
        return app(VendorSchedule::class)->stateAt($this, $at ?? now());
    }
}

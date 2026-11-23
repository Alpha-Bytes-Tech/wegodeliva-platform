<?php

namespace App\Models;

use App\Enums\VatTreatment;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A product sold by one vendor (spec 03 §3.3). Ratings are not mass-assignable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property string $name
 * @property string|null $description
 * @property string|null $sku
 * @property string|null $barcode
 * @property int $price_kobo
 * @property int|null $compare_at_price_kobo
 * @property string|null $unit
 * @property string|null $unit_size
 * @property int|null $pack_count
 * @property int|null $stock_qty
 * @property VatTreatment|null $vat_treatment
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_deliverable
 * @property bool $requires_prescription
 * @property bool $age_restricted
 * @property int $sort_order
 * @property string $rating_avg
 * @property int $rating_count
 * @property string|null $pricing_unit null for goods; fixed | hour | day | month for services
 * @property int|null $min_units
 * @property int|null $max_units
 * @property string|null $location_mode at_customer | at_provider | either
 * @property string|null $callout_fee_mode none | flat | per_km
 * @property int $callout_fee_kobo
 * @property int|null $capacity
 * @property int|null $duration_minutes
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'sku', 'barcode', 'price_kobo', 'compare_at_price_kobo', 'unit', 'unit_size',
        'pack_count', 'stock_qty', 'vat_treatment', 'is_active', 'is_featured', 'is_deliverable',
        'requires_prescription', 'age_restricted', 'sort_order',
        'pricing_unit', 'min_units', 'max_units', 'location_mode', 'callout_fee_mode', 'callout_fee_kobo', 'capacity', 'duration_minutes',
    ];

    /** Same defaults as the database, so new records serialise correctly. @var array<string, mixed> */
    protected $attributes = ['callout_fee_kobo' => 0, 'is_active' => true, 'is_featured' => false, 'is_deliverable' => true, 'requires_prescription' => false, 'age_restricted' => false, 'sort_order' => 0, 'rating_avg' => '0.00', 'rating_count' => 0];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** A bookable service (spec 03 "Services", ADR 0014) rather than goods. */
    public function isService(): bool
    {
        return $this->pricing_unit !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_kobo' => 'integer',
            'min_units' => 'integer',
            'max_units' => 'integer',
            'callout_fee_kobo' => 'integer',
            'capacity' => 'integer',
            'duration_minutes' => 'integer',
            'compare_at_price_kobo' => 'integer',
            'pack_count' => 'integer',
            'stock_qty' => 'integer',
            'vat_treatment' => VatTreatment::class,
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_deliverable' => 'boolean',
            'requires_prescription' => 'boolean',
            'age_restricted' => 'boolean',
            'sort_order' => 'integer',
            'rating_count' => 'integer',
        ];
    }

    /**
     * Selling price: the promotional price when it is set and lower (spec 05 §5.1).
     */
    public function sellingPriceKobo(): int
    {
        $promo = $this->compare_at_price_kobo;

        return $promo !== null && $promo < $this->price_kobo ? $promo : $this->price_kobo;
    }

    public function inStock(): bool
    {
        return $this->stock_qty === null || $this->stock_qty > 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsToMany<VendorMenu, $this> */
    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(VendorMenu::class, 'product_vendor_menu');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return BelongsToMany<OptionGroup, $this> */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}

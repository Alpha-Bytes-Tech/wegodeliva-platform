<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Models\VendorMenu;
use App\Services\Storage\StorageSigner;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as customers see it; `forVendor()` adds stock numbers and inactive option groups.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    private bool $vendorView = false;

    public function forVendor(): static
    {
        $this->vendorView = true;

        return $this;
    }

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $selling = $this->sellingPriceKobo();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => Money::of($selling),
            // The crossed-out price when a promotional price applies.
            'original_price' => $selling < $this->price_kobo ? Money::of($this->price_kobo) : null,
            'unit' => $this->unit,
            'unit_size' => $this->unit_size,
            'pack_count' => $this->pack_count,
            'in_stock' => $this->inStock(),
            'is_featured' => $this->is_featured,
            'is_deliverable' => $this->is_deliverable,
            'requires_prescription' => $this->requires_prescription,
            'age_restricted' => $this->age_restricted,
            'service' => $this->when($this->resource->isService(), fn () => [
                'pricing_unit' => $this->pricing_unit,
                'min_units' => $this->min_units,
                'max_units' => $this->max_units,
                'location_mode' => $this->location_mode,
                'callout_fee_mode' => $this->callout_fee_mode ?? 'none',
                'callout_fee' => Money::of($this->callout_fee_kobo),
                'duration_minutes' => $this->duration_minutes,
                'capacity' => $this->capacity ?? 1,
            ]),
            'rating' => ['average' => (float) $this->rating_avg, 'count' => $this->rating_count],
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn (ProductImage $i) => $i->path)->all()),
            'image_urls' => $this->whenLoaded('images', fn () => $this->images->map(fn (ProductImage $i) => app(StorageSigner::class)->readUrl($i->path))->all()),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->all()),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->all()),
            'menu_ids' => $this->whenLoaded('menus', fn () => $this->menus->map(fn (VendorMenu $m) => $m->uuid)->all()),
            'option_groups' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups
                ->filter(fn (OptionGroup $g) => $this->vendorView || $g->is_active)
                ->map(function (OptionGroup $g) {
                    $resource = new OptionGroupResource($g);
                    $resource->activeOptionsOnly = ! $this->vendorView;

                    return $resource;
                })->values()),
            $this->mergeWhen($this->vendorView, fn () => [
                'sku' => $this->sku,
                'barcode' => $this->barcode,
                'price_kobo' => $this->price_kobo,
                'compare_at_price_kobo' => $this->compare_at_price_kobo,
                'stock_qty' => $this->stock_qty,
                'vat_treatment' => $this->vat_treatment?->value,
                'is_active' => $this->is_active,
                'sort_order' => $this->sort_order,
            ]),
        ];
    }
}

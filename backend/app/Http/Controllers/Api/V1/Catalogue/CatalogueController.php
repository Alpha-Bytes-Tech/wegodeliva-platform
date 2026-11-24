<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\NearbyRequest;
use App\Http\Requests\Catalogue\OptionalPointRequest;
use App\Http\Requests\Catalogue\PointRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PackageTypeResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VendorResource;
use App\Http\Resources\VendorTypeResource;
use App\Models\PackageType;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Catalogue\ZoneLocator;
use App\Services\Orders\BookingSlots;
use App\Services\Pricing\DeliveryQuoter;
use App\Services\Storage\StorageImages;
use App\Support\Geo;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Public catalogue (spec 14 §14.2 "Catalogue"). No sign-in needed; only active vendors and
 * active products are visible, and anything else answers 404.
 */
class CatalogueController extends Controller
{
    public function __construct(private readonly StorageImages $images) {}

    public function vendorTypes(): AnonymousResourceCollection
    {
        return VendorTypeResource::collection(
            VendorType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** Free start times for a service on a day (spec 09 §9.9 "Book a service"). */
    public function slots(Request $request, Product $product, BookingSlots $slots): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'units' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $product->loadMissing('vendor.hours');
        abort_unless($product->is_active && $product->isService() && $product->vendor->status === VendorStatus::Active, 404);

        $day = Carbon::createFromFormat('Y-m-d', $data['date'], (string) config('wegodeliva.timezone'));
        abort_if($day === null, 404);

        return response()->json(['data' => [
            'date' => $data['date'],
            'units' => (int) ($data['units'] ?? 1),
            'starts' => $slots->available($product, $day, (int) ($data['units'] ?? 1)),
        ]]);
    }

    /** Parcel package types customers can choose from (spec 09 §9.6). */
    public function packageTypes(): AnonymousResourceCollection
    {
        return PackageTypeResource::collection(
            PackageType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function categories(VendorType $vendorType): AnonymousResourceCollection
    {
        abort_unless($vendorType->is_active, 404);

        return CategoryResource::collection(
            $vendorType->categories()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** "Do you deliver here?" — the zone and city for a point. */
    public function zoneLookup(PointRequest $request, ZoneLocator $locator): JsonResponse
    {
        [$lat, $lng] = $request->point() ?? [0.0, 0.0];
        $zone = $locator->zoneAt($lat, $lng);
        $city = $locator->cityAt($lat, $lng, $zone);

        return response()->json(['data' => [
            'zone' => $zone === null ? null : ['id' => $zone->uuid, 'name' => $zone->name, 'is_campus' => $zone->is_campus],
            'city' => $city === null ? null : ['slug' => $city->slug, 'name' => $city->name],
            'covered' => $city !== null,
        ]]);
    }

    /** Active vendors within browsing distance, nearest first. */
    public function vendors(NearbyRequest $request): AnonymousResourceCollection
    {
        $query = $this->nearby($request->lat(), $request->lng())
            ->with('vendorType', 'hours')
            ->when($request->input('vendor_type'), fn (Builder $q, $slug) => $q->whereHas('vendorType', fn (Builder $t) => $t->where('slug', $slug)))
            ->when($request->input('category'), fn (Builder $q, $category) => $q->whereHas(
                'products',
                fn (Builder $p) => $p->where('is_active', true)->whereHas('categories', fn (Builder $c) => $c->where('categories.slug', $category)),
            ))
            ->when($request->input('q'), fn (Builder $q, string $term) => $q->whereRaw('name ILIKE ?', ['%'.$this->escapeLike($term).'%']))
            ->orderBy('distance_m')
            ->orderByDesc('is_featured')
            ->orderBy('id');

        $page = $query->simplePaginate($request->perPage())->withQueryString();
        $this->images->warm($page->items());

        return VendorResource::collection($page);
    }

    public function vendor(OptionalPointRequest $request, Vendor $vendor): VendorResource
    {
        $vendor = $this->activeVendor($vendor);
        $point = $request->point();
        if ($point !== null) {
            $vendor->setAttribute('distance_m', Geo::distanceMeters($point[0], $point[1], (float) $vendor->lat, (float) $vendor->lng));
        }

        $this->images->warm([$vendor]);

        return new VendorResource($vendor->load('vendorType', 'hours', 'menus'));
    }

    /** Whether the vendor delivers to a point, the fee and a delivery time range (spec 05 §5.2, §5.10). */
    public function deliveryQuote(PointRequest $request, Vendor $vendor, DeliveryQuoter $quoter): JsonResponse
    {
        $vendor = $this->activeVendor($vendor);
        [$lat, $lng] = $request->point() ?? [0.0, 0.0];
        $quote = $quoter->quote($vendor, $lat, $lng);

        return response()->json(['data' => [
            'deliverable' => $quote->deliverable,
            'reason' => $quote->reason,
            'fee' => $quote->deliverable ? Money::of($quote->feeKobo) : null,
            'distance_m' => $quote->distanceM,
            'eta_minutes' => $quote->deliverable ? ['min' => $quote->etaMinMinutes, 'max' => $quote->etaMaxMinutes] : null,
        ]]);
    }

    /** A vendor's active products, optionally filtered by menu section or name. */
    public function products(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $vendor = $this->activeVendor($vendor);
        $validated = $request->validate([
            'menu' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('wegodeliva.catalogue.max_page_size')],
        ]);

        $products = $vendor->products()
            ->available()
            ->with('images', 'optionGroups.options')
            ->when($validated['menu'] ?? null, fn (Builder $q, string $menu) => $q->whereHas('menus', fn (Builder $m) => $m->where('vendor_menus.uuid', $menu)->where('vendor_menus.is_active', true)))
            ->when($validated['q'] ?? null, fn (Builder $q, string $term) => $q->whereRaw('products.name ILIKE ?', ['%'.$this->escapeLike($term).'%']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 30))
            ->withQueryString();

        $this->images->warm(products: $products->items());

        return ProductResource::collection($products);
    }

    public function product(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);
        $vendor = $this->activeVendor($product->vendor()->firstOrFail());

        $product->load('images', 'categories', 'tags', 'optionGroups.options');
        $this->images->warm(products: [$product]);

        return response()->json(['data' => (new ProductResource($product))->resolve() + [
            'vendor' => ['id' => $vendor->uuid, 'slug' => $vendor->slug, 'name' => $vendor->name],
        ]]);
    }

    /** Vendors and products whose name matches, among vendors within browsing distance. */
    public function search(NearbyRequest $request): JsonResponse
    {
        $request->validate(['q' => ['required']]);
        $term = '%'.$this->escapeLike((string) $request->input('q')).'%';
        $limit = min($request->perPage(), 20);

        $nearbyIds = $this->nearby($request->lat(), $request->lng())->pluck('id');

        $vendors = Vendor::query()->whereKey($nearbyIds)->whereRaw('name ILIKE ?', [$term])
            ->with('vendorType', 'hours')->orderBy('name')->limit($limit)->get();
        $products = Product::query()->available()->whereIn('vendor_id', $nearbyIds)->whereRaw('name ILIKE ?', [$term])
            ->with('images', 'vendor')->orderBy('name')->limit($limit)->get();
        $this->images->warm($vendors, $products);

        return response()->json(['data' => [
            'vendors' => VendorResource::collection($vendors)->resolve(),
            'products' => $products->map(fn (Product $p) => (new ProductResource($p))->resolve() + [
                'vendor' => ['id' => $p->vendor->uuid, 'slug' => $p->vendor->slug, 'name' => $p->vendor->name],
            ])->all(),
        ]]);
    }

    /** @return Builder<Vendor> */
    private function nearby(float $lat, float $lng): Builder
    {
        $distance = Geo::distanceSql('vendors.lat', 'vendors.lng');

        return Vendor::query()
            ->active()
            ->select('vendors.*')
            ->selectRaw("round({$distance}) as distance_m", [$lat, $lat, $lng])
            ->whereRaw("{$distance} <= ?", [$lat, $lat, $lng, (int) config('wegodeliva.catalogue.browse_radius_m')]);
    }

    private function activeVendor(Vendor $vendor): Vendor
    {
        abort_unless($vendor->status === VendorStatus::Active, 404);

        return $vendor;
    }

    private function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}

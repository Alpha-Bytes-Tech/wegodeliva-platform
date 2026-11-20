<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VendorResource;
use App\Models\Favourite;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Favourite vendors and products (spec 14 §14.2 "Favourites"). Adding is idempotent; vendors
 * and products that are no longer visible drop out of the list but stay saved.
 */
class FavouriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favourites = $this->user($request)->favourites()
            ->with(['vendor.vendorType', 'vendor.hours', 'product.images', 'product.vendor'])
            ->orderByDesc('id')
            ->get();

        $vendors = $favourites->pluck('vendor')->filter(fn (?Vendor $v) => $v !== null && $v->status === VendorStatus::Active)->values();
        $products = $favourites->pluck('product')->filter(fn (?Product $p) => $p !== null && $p->is_active && $p->vendor->status === VendorStatus::Active)->values();

        return response()->json(['data' => [
            'vendors' => VendorResource::collection($vendors)->resolve(),
            'products' => $products->map(fn (Product $p) => (new ProductResource($p))->resolve() + [
                'vendor' => ['id' => $p->vendor->uuid, 'slug' => $p->vendor->slug, 'name' => $p->vendor->name],
            ])->all(),
        ]]);
    }

    public function addVendor(Request $request, string $uuid): Response
    {
        $vendor = Vendor::query()->active()->where('uuid', $uuid)->firstOrFail();
        Favourite::query()->firstOrCreate(['user_id' => $this->user($request)->id, 'vendor_id' => $vendor->id]);

        return response()->noContent();
    }

    public function removeVendor(Request $request, string $uuid): Response
    {
        $this->user($request)->favourites()->whereHas('vendor', fn ($q) => $q->where('uuid', $uuid))->delete();

        return response()->noContent();
    }

    public function addProduct(Request $request, string $uuid): Response
    {
        $product = Product::query()->available()->where('uuid', $uuid)->whereHas('vendor', fn ($q) => $q->active())->firstOrFail();
        Favourite::query()->firstOrCreate(['user_id' => $this->user($request)->id, 'product_id' => $product->id]);

        return response()->noContent();
    }

    public function removeProduct(Request $request, string $uuid): Response
    {
        $this->user($request)->favourites()->whereHas('product', fn ($q) => $q->where('uuid', $uuid))->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

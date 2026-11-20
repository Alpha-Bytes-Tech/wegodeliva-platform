<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Requests\Vendor\ProductRequest;
use App\Http\Requests\Vendor\StockRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** A store's products, including inactive and out-of-stock ones (spec 10 "Products"). */
class ProductController extends VendorController
{
    private const RELATIONS = ['images', 'categories', 'tags', 'menus', 'optionGroups.options'];

    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'menu' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('wegodeliva.catalogue.max_page_size')],
        ]);

        $products = $vendor->products()
            ->with(self::RELATIONS)
            ->when($validated['q'] ?? null, fn (Builder $q, string $term) => $q->whereRaw('products.name ILIKE ?', ['%'.addcslashes($term, '\\%_').'%']))
            ->when($validated['menu'] ?? null, fn (Builder $q, string $menu) => $q->whereHas('menus', fn (Builder $m) => $m->where('vendor_menus.uuid', $menu)))
            ->when(isset($validated['active']), fn (Builder $q) => $q->where('is_active', (bool) $validated['active']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 30))
            ->withQueryString();

        $collection = ProductResource::collection($products);
        $collection->collection->each(fn (ProductResource $r) => $r->forVendor());

        return $collection;
    }

    public function store(ProductRequest $request, Vendor $vendor): JsonResponse
    {
        $this->member($request, $vendor);

        $product = DB::transaction(function () use ($request, $vendor): Product {
            $product = new Product($request->safe()->except(['category_ids', 'tag_ids', 'menu_ids', 'option_group_ids', 'images']));
            $product->vendor()->associate($vendor);
            $product->save();
            $this->syncLinks($product, $vendor, $request);

            return $product;
        });

        return $this->resource($product)->response()->setStatusCode(201);
    }

    public function show(Request $request, Vendor $vendor, Product $product): ProductResource
    {
        $this->member($request, $vendor);

        return $this->resource($product);
    }

    public function update(ProductRequest $request, Vendor $vendor, Product $product): ProductResource
    {
        $this->member($request, $vendor);

        DB::transaction(function () use ($request, $vendor, $product): void {
            $product->fill($request->safe()->except(['category_ids', 'tag_ids', 'menu_ids', 'option_group_ids', 'images']))->save();
            $this->syncLinks($product, $vendor, $request);
        });

        return $this->resource($product);
    }

    /** Quick stock edit; null means unlimited. */
    public function stock(StockRequest $request, Vendor $vendor, Product $product): ProductResource
    {
        $this->member($request, $vendor);
        $product->forceFill(['stock_qty' => $request->validated('stock_qty')])->save();

        return $this->resource($product);
    }

    /** Soft delete: past orders keep their own snapshot of the product. */
    public function destroy(Request $request, Vendor $vendor, Product $product): Response
    {
        $this->member($request, $vendor);
        $product->delete();

        return response()->noContent();
    }

    /** Replaces only the link lists the request sent. */
    private function syncLinks(Product $product, Vendor $vendor, ProductRequest $request): void
    {
        if ($request->has('category_ids')) {
            $product->categories()->sync($request->validated('category_ids'));
        }
        if ($request->has('tag_ids')) {
            $product->tags()->sync($request->validated('tag_ids'));
        }
        if ($request->has('menu_ids')) {
            $product->menus()->sync($vendor->menus()->whereIn('uuid', $request->validated('menu_ids'))->pluck('id'));
        }
        if ($request->has('option_group_ids')) {
            /** @var list<string> $order */
            $order = $request->validated('option_group_ids');
            $ids = $vendor->optionGroups()->whereIn('uuid', $order)->pluck('id', 'uuid');
            $product->optionGroups()->sync(collect($order)->mapWithKeys(fn (string $uuid, int $i) => [$ids[$uuid] => ['sort_order' => $i]])->all());
        }
        if ($request->has('images')) {
            $product->images()->delete();
            foreach (array_values($request->validated('images')) as $i => $path) {
                $product->images()->create(['path' => $path, 'sort_order' => $i]);
            }
        }
    }

    private function resource(Product $product): ProductResource
    {
        return (new ProductResource($product->load(self::RELATIONS)))->forVendor();
    }
}

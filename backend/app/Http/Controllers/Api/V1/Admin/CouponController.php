<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Party;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\AuditLogger;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Coupons (spec 12.6). Coupons are switched off rather than deleted, so redemption history
 * stays intact. Every change is audited.
 */
class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-promotions');
        $validated = $request->validate([
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'min:2', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $coupons = Coupon::query()
            ->with('vendorType')
            ->withCount(['redemptions' => fn (Builder $q) => $q->whereNull('released_at')])
            ->when(isset($validated['active']), fn (Builder $q) => $q->where('is_active', (bool) $validated['active']))
            ->when($validated['q'] ?? null, fn (Builder $q, string $term) => $q->where('code', 'like', addcslashes(Coupon::normaliseCode($term), '\\%_').'%'))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return CouponResource::collection($coupons);
    }

    public function show(Coupon $coupon): CouponResource
    {
        Gate::authorize('manage-promotions');

        return $this->resource($coupon);
    }

    public function store(CouponRequest $request): JsonResponse
    {
        Gate::authorize('manage-promotions');
        /** @var User $admin */
        $admin = $request->user();

        $coupon = new Coupon(['code' => $request->validated('code')]);
        $coupon->created_by = $admin->id;
        $this->save($coupon, $request->validated());

        $this->audit->record($admin, 'coupon.created', 'coupon', $coupon->uuid, after: $this->snapshot($coupon));

        return $this->resource($coupon)->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        Gate::authorize('manage-promotions');
        /** @var User $admin */
        $admin = $request->user();

        $before = $this->snapshot($coupon);
        $this->save($coupon, $request->validated());
        $this->audit->record($admin, 'coupon.updated', 'coupon', $coupon->uuid, $before, $this->snapshot($coupon));

        return $this->resource($coupon);
    }

    /** @param  array<string, mixed>  $data */
    private function save(Coupon $coupon, array $data): void
    {
        DB::transaction(function () use ($coupon, $data): void {
            $coupon->fill(collect($data)->except(['code', 'vendor_type', 'vendor_ids', 'product_ids'])->all());
            $coupon->funded_by ??= Party::Vendor;
            $coupon->min_order_kobo ??= 0;
            $scope = $data['scope'];
            $coupon->vendor_type_id = $scope === 'vendor_type'
                ? VendorType::query()->where('slug', $data['vendor_type'])->value('id')
                : null;
            $coupon->save();

            $coupon->vendors()->sync($scope === 'vendors'
                ? Vendor::query()->whereIn('uuid', $data['vendor_ids'])->pluck('id')->all()
                : []);
            $coupon->products()->sync($scope === 'products'
                ? Product::query()->whereIn('uuid', $data['product_ids'])->pluck('id')->all()
                : []);
        });
    }

    private function resource(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon->load('vendorType', 'vendors', 'products')->loadCount(['redemptions' => fn (Builder $q) => $q->whereNull('released_at')]));
    }

    /** @return array<string, mixed> */
    private function snapshot(Coupon $coupon): array
    {
        return collect($coupon->only(['code', 'discount_type', 'value', 'max_discount_kobo', 'min_order_kobo', 'applies_to', 'scope', 'funded_by', 'is_active', 'max_uses_total', 'max_uses_per_user']))
            ->map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v)
            ->all();
    }
}

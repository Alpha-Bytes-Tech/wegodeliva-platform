<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon as admins see it, with its usage so far.
 *
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type->value,
            'value' => $this->value,
            'max_discount_kobo' => $this->max_discount_kobo,
            'min_order_kobo' => $this->min_order_kobo,
            'applies_to' => $this->applies_to->value,
            'scope' => $this->scope->value,
            'vendor_type' => $this->whenLoaded('vendorType', fn () => $this->vendorType?->slug),
            'vendor_ids' => $this->whenLoaded('vendors', fn () => $this->vendors->map(fn (Vendor $v) => $v->uuid)->all()),
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->map(fn (Product $p) => $p->uuid)->all()),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'max_uses_total' => $this->max_uses_total,
            'max_uses_per_user' => $this->max_uses_per_user,
            'first_order_only' => $this->first_order_only,
            'funded_by' => $this->funded_by->value,
            'is_active' => $this->is_active,
            'uses' => $this->whenCounted('redemptions'),
        ];
    }
}

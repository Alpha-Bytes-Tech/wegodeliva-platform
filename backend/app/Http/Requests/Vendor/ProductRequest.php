<?php

namespace App\Http\Requests\Vendor;

use App\Enums\VatTreatment;
use App\Http\Requests\ApiRequest;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Validation\Rule;

/**
 * Create (POST) or update (PATCH) a product (spec 10 "New / edit product"). Categories and tags
 * must belong to the store's vendor type; menus and option groups to the store itself. Image
 * paths must sit under the store's folder in the product-images bucket.
 */
class ProductRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->route('vendor');
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $maxImages = (int) config('wegodeliva.catalogue.max_product_images');
        // A services store lists bookable services (ADR 0014); other stores list goods.
        $vendor->loadMissing('vendorType');
        $services = $vendor->vendorType->slug === Order::TYPE_SERVICE;
        $service = fn (array $rules) => $services ? $rules : ['prohibited'];

        return [
            'name' => [$required, 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price_kobo' => [$required, 'integer', 'min:0', 'max:1000000000'],
            'compare_at_price_kobo' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:16'],
            'unit_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'pack_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'stock_qty' => $services ? ['prohibited'] : ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'vat_treatment' => ['sometimes', 'nullable', Rule::enum(VatTreatment::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_deliverable' => ['sometimes', 'boolean'],
            'requires_prescription' => ['sometimes', 'boolean'],
            'age_restricted' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'category_ids' => ['sometimes', 'array', 'max:10'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('vendor_type_id', $vendor->vendor_type_id)],
            'tag_ids' => ['sometimes', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('vendor_type_id', $vendor->vendor_type_id)],
            'menu_ids' => ['sometimes', 'array', 'max:20'],
            'menu_ids.*' => ['string', 'distinct', Rule::exists('vendor_menus', 'uuid')->where('vendor_id', $vendor->id)],
            'option_group_ids' => ['sometimes', 'array', 'max:20'],
            'option_group_ids.*' => ['string', 'distinct', Rule::exists('option_groups', 'uuid')->where('vendor_id', $vendor->id)],
            'pricing_unit' => $service([$required, 'in:fixed,hour,day,month']),
            'min_units' => $service(['sometimes', 'nullable', 'integer', 'min:1', 'max:1000']),
            'max_units' => $service(['sometimes', 'nullable', 'integer', 'min:1', 'max:1000', 'gte:min_units']),
            'location_mode' => $service([$required, 'in:at_customer,at_provider,either']),
            'callout_fee_mode' => $service(['sometimes', 'nullable', 'in:none,flat,per_km']),
            'callout_fee_kobo' => $service(['sometimes', 'integer', 'min:0', 'max:100000000']),
            'capacity' => $service(['sometimes', 'nullable', 'integer', 'min:1', 'max:100']),
            'duration_minutes' => $service(['sometimes', 'nullable', 'integer', 'min:5', 'max:1440']),
            'images' => ['sometimes', 'array', "max:{$maxImages}"],
            'images.*' => ['string', 'distinct', 'max:255', 'not_regex:#\.\.#', 'regex:#^vendors/'.preg_quote($vendor->uuid, '#').'/products/[A-Za-z0-9._/-]+$#'],
        ];
    }
}

<?php

namespace Tests\Support;

use App\Enums\Party;
use App\Enums\VatTreatment;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Fee;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Pricing\CouponContext;
use App\Services\Pricing\PricedLine;
use App\Services\Pricing\PricedOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** In-memory builders for pricing unit tests (no database). */
final class Pricing
{
    private static int $nextId = 1;

    public static function line(int $unitKobo, int $qty = 1, VatTreatment $vat = VatTreatment::Standard, int $productId = 0, int $optionsKobo = 0): PricedLine
    {
        $id = $productId ?: self::$nextId++;
        $options = $optionsKobo > 0 ? [new PricedOption('g', 'Extras', 'o', 'Extra', $optionsKobo)] : [];

        return new PricedLine($id, "product-{$id}", "Product {$id}", $unitKobo, $qty, $options, $vat);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function coupon(array $attributes = []): Coupon
    {
        $coupon = new Coupon;
        $coupon->forceFill(array_merge([
            'id' => self::$nextId++,
            'code' => 'SAVE',
            'discount_type' => 'fixed',
            'value' => 50000,
            'max_discount_kobo' => null,
            'min_order_kobo' => 0,
            'applies_to' => 'order_subtotal',
            'scope' => 'all',
            'vendor_type_id' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_uses_total' => null,
            'max_uses_per_user' => null,
            'first_order_only' => false,
            'funded_by' => 'vendor',
            'is_active' => true,
        ], $attributes));
        $coupon->setRelation('vendors', new Collection);
        $coupon->setRelation('products', new Collection);

        return $coupon;
    }

    /** @param  list<PricedLine>  $lines */
    public static function context(array $lines, ?int $deliveryFee = 100000, array $overrides = []): CouponContext
    {
        return new CouponContext(
            vendorId: $overrides['vendorId'] ?? 10,
            vendorTypeId: $overrides['vendorTypeId'] ?? 1,
            lines: $lines,
            deliveryFeeKobo: $deliveryFee,
            isFirstOrder: $overrides['isFirstOrder'] ?? false,
            usesTotal: $overrides['usesTotal'] ?? 0,
            usesByCustomer: $overrides['usesByCustomer'] ?? 0,
            now: $overrides['now'] ?? Carbon::parse('2026-12-01 12:00:00'),
        );
    }

    public static function fee(string $type, int $value, Party $beneficiary = Party::Platform, bool $active = true): Fee
    {
        $fee = new Fee;
        $fee->forceFill(['id' => self::$nextId++, 'name' => ucfirst($beneficiary->value).' fee', 'type' => $type, 'value' => $value, 'beneficiary' => $beneficiary->value, 'is_active' => $active]);

        return $fee;
    }

    public static function vendor(int $id): Vendor
    {
        $vendor = new Vendor;
        $vendor->forceFill(['id' => $id]);

        return $vendor;
    }

    /**
     * @param  list<OptionGroup>  $groups
     * @param  list<string|null>  $categoryTreatments
     */
    public static function product(int $price, ?int $promo = null, array $groups = [], ?VatTreatment $vat = null, array $categoryTreatments = []): Product
    {
        $product = new Product;
        $product->forceFill(['id' => self::$nextId++, 'uuid' => 'p-'.self::$nextId, 'name' => 'Jollof rice', 'price_kobo' => $price, 'compare_at_price_kobo' => $promo, 'vat_treatment' => $vat?->value]);
        $product->setRelation('optionGroups', new Collection($groups));
        $product->setRelation('categories', new Collection(array_map(function (?string $t) {
            $c = new Category;
            $c->forceFill(['vat_treatment' => $t]);

            return $c;
        }, $categoryTreatments)));

        return $product;
    }

    /** @param  array<string, int>  $options  option uuid => price; inactive when the uuid starts with "x" */
    public static function group(string $name, array $options, int $min = 0, int $max = 1, bool $required = false, bool $active = true): OptionGroup
    {
        $group = new OptionGroup;
        $group->forceFill(['id' => self::$nextId++, 'uuid' => "g-{$name}", 'name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_required' => $required, 'is_active' => $active]);
        $group->setRelation('options', new Collection(array_map(function (string $uuid, int $price) {
            $o = new Option;
            $o->forceFill(['uuid' => $uuid, 'name' => ucfirst($uuid), 'price_kobo' => $price, 'is_active' => ! str_starts_with($uuid, 'x')]);

            return $o;
        }, array_keys($options), $options)));

        return $group;
    }
}

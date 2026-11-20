<?php

use App\Enums\VendorStatus;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorType;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;

/** Yaba, Lagos. Factory vendors sit about 1 km away. */
const YABA = ['lat' => 6.5095, 'lng' => 3.3711];

beforeEach(fn () => $this->seed([VendorTypeSeeder::class, CitySeeder::class]));

it('lists active vendor types and their categories without signing in', function () {
    $food = VendorType::query()->where('slug', 'food')->firstOrFail();
    $food->categories()->create(['name' => 'Rice', 'slug' => 'rice']);
    $food->categories()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
    VendorType::query()->where('slug', 'parcels')->update(['is_active' => false]);

    $types = $this->getJson('/api/v1/catalogue/vendor-types')->assertOk()->json('data.*.slug');
    expect($types)->toContain('food')->not->toContain('parcels');

    $this->getJson('/api/v1/catalogue/vendor-types/food/categories')
        ->assertOk()
        ->assertJsonPath('data.*.slug', ['rice']);
    $this->getJson('/api/v1/catalogue/vendor-types/parcels/categories')->assertNotFound();
});

it('lists active vendors near a point, nearest first, with distance and open state', function () {
    $near = Vendor::factory()->at(6.5100, 3.3720)->create(['name' => 'Near']);
    $far = Vendor::factory()->at(6.5600, 3.3720)->create(['name' => 'Far']);
    Vendor::factory()->at(6.5100, 3.3720)->status(VendorStatus::PendingApproval)->create();
    Vendor::factory()->at(6.5100, 3.3720)->status(VendorStatus::Suspended)->create();
    Vendor::factory()->at(7.3775, 3.9470)->create(['name' => 'Ibadan']); // ~130 km away

    $response = $this->getJson('/api/v1/catalogue/vendors?'.http_build_query(YABA))->assertOk();

    expect($response->json('data.*.id'))->toBe([$near->uuid, $far->uuid]);
    $response->assertJsonPath('data.0.open_state', 'open')
        ->assertJsonPath('data.0.deliverable', true)
        ->assertJsonPath('data.1.deliverable', false) // ~5.6 km, outside the 5 km radius
        ->assertJsonPath('data.0.min_order.formatted', '₦0.00')
        ->assertJsonMissingPath('data.0.commission_rate_bp')
        ->assertJsonMissingPath('data.0.status');
    expect($response->json('data.0.distance_m'))->toBeLessThan(200);
});

it('filters vendors by type, category and name', function () {
    $food = Vendor::factory()->create(['name' => 'Mama Put Kitchen']);
    $pharmacy = Vendor::factory()->ofType('pharmacy')->create(['name' => 'Yaba Pharmacy']);
    $rice = $food->vendorType->categories()->create(['name' => 'Rice', 'slug' => 'rice']);
    $jollof = Product::factory()->for($food)->create();
    $jollof->categories()->attach($rice);

    $query = fn (array $extra) => $this->getJson('/api/v1/catalogue/vendors?'.http_build_query(YABA + $extra))->assertOk()->json('data.*.id');

    expect($query(['vendor_type' => 'pharmacy']))->toBe([$pharmacy->uuid])
        ->and($query(['category' => 'rice']))->toBe([$food->uuid])
        ->and($query(['q' => 'mama put']))->toBe([$food->uuid])
        ->and($query(['q' => '100%']))->toBe([]);
});

it('validates the point and filters', function () {
    $error = expectApiError($this->getJson('/api/v1/catalogue/vendors?lat=200&lng=3.3'), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('lat');

    expectApiError($this->getJson('/api/v1/catalogue/vendors?'.http_build_query(YABA + ['vendor_type' => 'taxi'])), 400, 'validation_failed');
    expectApiError($this->getJson('/api/v1/catalogue/vendors?'.http_build_query(YABA + ['per_page' => 51])), 400, 'validation_failed');
});

it('shows vendor details with hours and active menus, and hides inactive vendors', function () {
    $vendor = Vendor::factory()->create();
    $vendor->menus()->create(['name' => 'Rice dishes']);
    $vendor->menus()->create(['name' => 'Old menu', 'is_active' => false]);
    $pending = Vendor::factory()->status(VendorStatus::PendingApproval)->create();

    $this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}?".http_build_query(YABA))
        ->assertOk()
        ->assertJsonPath('data.id', $vendor->uuid)
        ->assertJsonCount(7, 'data.hours')
        ->assertJsonPath('data.menus.*.name', ['Rice dishes'])
        ->assertJsonPath('data.deliverable', true);

    $this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}")->assertOk()->assertJsonMissingPath('data.distance_m');
    expectApiError($this->getJson("/api/v1/catalogue/vendors/{$pending->slug}"), 404, 'not_found');
});

it("lists a vendor's active products with selling price, options and menu filter", function () {
    $vendor = Vendor::factory()->create();
    $menu = $vendor->menus()->create(['name' => 'Drinks']);
    $group = $vendor->optionGroups()->create(['name' => 'Size', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
    $group->options()->create(['name' => 'Large', 'price_kobo' => 50000]);
    $group->options()->create(['name' => 'Retired', 'price_kobo' => 1, 'is_active' => false]);

    $promo = Product::factory()->for($vendor)->create(['name' => 'Zobo', 'price_kobo' => 100000, 'compare_at_price_kobo' => 80000, 'stock_qty' => 0]);
    $promo->menus()->attach($menu);
    $promo->optionGroups()->attach($group);
    Product::factory()->for($vendor)->create(['name' => 'Jollof', 'price_kobo' => 300000, 'compare_at_price_kobo' => 350000]);
    Product::factory()->for($vendor)->create(['is_active' => false]);

    $all = $this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}/products")->assertOk();
    $all->assertJsonCount(2, 'data');

    $drinks = $this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}/products?menu={$menu->uuid}")->assertOk();
    $drinks->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.price.amount_kobo', 80000)
        ->assertJsonPath('data.0.original_price.amount_kobo', 100000)
        ->assertJsonPath('data.0.in_stock', false)
        ->assertJsonPath('data.0.option_groups.0.options.*.name', ['Large'])
        ->assertJsonMissingPath('data.0.stock_qty')
        ->assertJsonMissingPath('data.0.price_kobo');

    // A higher "compare at" price is not a promotion.
    $this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}/products?q=jollof")
        ->assertJsonPath('data.0.price.amount_kobo', 300000)
        ->assertJsonPath('data.0.original_price', null);
});

it('shows a product with its vendor, and 404s for hidden products or vendors', function () {
    $vendor = Vendor::factory()->create();
    $product = Product::factory()->for($vendor)->create();
    $inactive = Product::factory()->for($vendor)->create(['is_active' => false]);
    $suspendedVendorProduct = Product::factory()->for(Vendor::factory()->status(VendorStatus::Suspended))->create();
    $deleted = Product::factory()->for($vendor)->create();
    $deleted->delete();

    $this->getJson("/api/v1/catalogue/products/{$product->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.vendor.slug', $vendor->slug);

    foreach ([$inactive, $suspendedVendorProduct, $deleted] as $hidden) {
        expectApiError($this->getJson("/api/v1/catalogue/products/{$hidden->uuid}"), 404, 'not_found');
    }
});

it('searches vendors and products near the customer only', function () {
    $near = Vendor::factory()->create(['name' => 'Suya Spot']);
    Product::factory()->for($near)->create(['name' => 'Beef suya']);
    Product::factory()->for($near)->create(['name' => 'Chicken suya', 'is_active' => false]);
    $far = Vendor::factory()->at(7.3775, 3.9470)->create(['name' => 'Suya Ibadan']);
    Product::factory()->for($far)->create(['name' => 'Ram suya']);

    $this->getJson('/api/v1/catalogue/search?'.http_build_query(YABA + ['q' => 'suya']))
        ->assertOk()
        ->assertJsonPath('data.vendors.*.name', ['Suya Spot'])
        ->assertJsonPath('data.products.*.name', ['Beef suya'])
        ->assertJsonPath('data.products.0.vendor.id', $near->uuid);

    expectApiError($this->getJson('/api/v1/catalogue/search?'.http_build_query(YABA)), 400, 'validation_failed');
});

it('finds the city for a point and reports when it is outside every launch city', function () {
    $this->getJson('/api/v1/catalogue/zones/lookup?'.http_build_query(YABA))
        ->assertOk()
        ->assertJsonPath('data.city.slug', 'lagos')
        ->assertJsonPath('data.covered', true);

    $this->getJson('/api/v1/catalogue/zones/lookup?lat=9.0765&lng=7.3986') // Abuja
        ->assertOk()
        ->assertJsonPath('data.city', null)
        ->assertJsonPath('data.covered', false);
});

it('rate-limits catalogue requests per IP', function () {
    foreach (range(1, 120) as $i) {
        $this->getJson('/api/v1/catalogue/vendor-types')->assertOk();
    }

    expectApiError($this->getJson('/api/v1/catalogue/vendor-types'), 429, 'rate_limited');
});

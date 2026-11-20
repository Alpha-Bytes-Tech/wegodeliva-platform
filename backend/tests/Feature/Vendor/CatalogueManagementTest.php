<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\VendorMemberRole;
use App\Models\Product;
use App\Models\Tag;
use App\Models\Vendor;

function staffToken(Vendor $vendor): string
{
    $user = makeUser(RoleName::VendorStaff);
    $vendor->members()->attach($user->id, ['role' => VendorMemberRole::Staff->value]);

    return tokenFor($user, ClientApp::Vendor);
}

it('manages menu sections', function () {
    $vendor = Vendor::factory()->create();
    $token = staffToken($vendor);
    $base = "/api/v1/vendor/stores/{$vendor->uuid}/menus";

    $id = $this->withToken($token)->postJson($base, ['name' => 'Rice dishes'])
        ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
    $this->withToken($token)->patchJson("{$base}/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.0.id', $id);
    $this->withToken($token)->deleteJson("{$base}/{$id}")->assertNoContent();
    expectApiError($this->withToken($token)->postJson($base, []), 400, 'validation_failed');
});

it('manages option groups and their choices', function () {
    $vendor = Vendor::factory()->create();
    $token = staffToken($vendor);
    $base = "/api/v1/vendor/stores/{$vendor->uuid}/option-groups";

    $group = $this->withToken($token)->postJson($base, [
        'name' => 'Choose your drink', 'is_required' => true, 'max_select' => 2,
        'options' => [['name' => 'Coke', 'price_kobo' => 50000], ['name' => 'Water']],
    ])->assertCreated()
        ->assertJsonPath('data.min_select', 1) // a required group needs at least one choice
        ->assertJsonPath('data.options.*.name', ['Coke', 'Water'])
        ->assertJsonPath('data.options.1.price.amount_kobo', 0)
        ->json('data');

    $this->withToken($token)->postJson("{$base}/{$group['id']}/options", ['name' => 'Fanta', 'price_kobo' => 50000])
        ->assertCreated()->assertJsonCount(3, 'data.options');
    $optionId = $group['options'][0]['id'];
    $this->withToken($token)->patchJson("{$base}/{$group['id']}/options/{$optionId}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.options.0.is_active', false);
    $this->withToken($token)->deleteJson("{$base}/{$group['id']}/options/{$optionId}")->assertNoContent();

    expectApiError($this->withToken($token)->patchJson("{$base}/{$group['id']}", ['min_select' => 3, 'max_select' => 2]), 400, 'validation_failed');
    $this->withToken($token)->getJson($base)->assertOk()->assertJsonCount(2, 'data.0.options');
    $this->withToken($token)->deleteJson("{$base}/{$group['id']}")->assertNoContent();
});

it("does not reach another store's menus, groups, options or products through its own store", function () {
    $mine = Vendor::factory()->create();
    $other = Vendor::factory()->create();
    $token = staffToken($mine);
    $menu = $other->menus()->create(['name' => 'Theirs']);
    $group = $other->optionGroups()->create(['name' => 'Theirs']);
    $option = $group->options()->create(['name' => 'Theirs']);
    $product = Product::factory()->for($other)->create();
    $myGroup = $mine->optionGroups()->create(['name' => 'Mine']);

    $base = "/api/v1/vendor/stores/{$mine->uuid}";
    expectApiError($this->withToken($token)->patchJson("{$base}/menus/{$menu->uuid}", ['name' => 'x']), 404, 'not_found');
    expectApiError($this->withToken($token)->deleteJson("{$base}/option-groups/{$group->uuid}"), 404, 'not_found');
    expectApiError($this->withToken($token)->deleteJson("{$base}/option-groups/{$myGroup->uuid}/options/{$option->uuid}"), 404, 'not_found');
    expectApiError($this->withToken($token)->getJson("{$base}/products/{$product->uuid}"), 404, 'not_found');
    expectApiError($this->withToken($token)->putJson("{$base}/products/{$product->uuid}/stock", ['stock_qty' => 0]), 404, 'not_found');

    expect($menu->fresh()->name)->toBe('Theirs')->and($product->fresh()->stock_qty)->toBeNull();
});

it('creates and updates a product with its links', function () {
    $vendor = Vendor::factory()->create();
    $token = staffToken($vendor);
    $category = $vendor->vendorType->categories()->create(['name' => 'Rice', 'slug' => 'rice']);
    $tag = Tag::query()->forceCreate(['vendor_type_id' => $vendor->vendor_type_id, 'name' => 'Spicy', 'slug' => 'spicy']);
    $menu = $vendor->menus()->create(['name' => 'Mains']);
    $drinks = $vendor->optionGroups()->create(['name' => 'Drink']);
    $sides = $vendor->optionGroups()->create(['name' => 'Side']);
    $base = "/api/v1/vendor/stores/{$vendor->uuid}/products";

    $product = $this->withToken($token)->postJson($base, [
        'name' => 'Jollof rice',
        'price_kobo' => 350000,
        'compare_at_price_kobo' => 300000,
        'stock_qty' => 20,
        'vat_treatment' => 'standard',
        'category_ids' => [$category->id],
        'tag_ids' => [$tag->id],
        'menu_ids' => [$menu->uuid],
        'option_group_ids' => [$sides->uuid, $drinks->uuid],
        'images' => ["vendors/{$vendor->uuid}/products/jollof-1.jpg"],
        'rating_avg' => 5,
    ])->assertCreated()
        ->assertJsonPath('data.price.amount_kobo', 300000)
        ->assertJsonPath('data.price_kobo', 350000)
        ->assertJsonPath('data.stock_qty', 20)
        ->assertJsonPath('data.categories.0.name', 'Rice')
        ->assertJsonPath('data.tags.0.name', 'Spicy')
        ->assertJsonPath('data.menu_ids', [$menu->uuid])
        ->assertJsonPath('data.option_groups.*.name', ['Side', 'Drink'])
        ->assertJsonPath('data.images', ["vendors/{$vendor->uuid}/products/jollof-1.jpg"])
        ->assertJsonPath('data.rating.average', 0)
        ->json('data');

    // Only the lists that are sent change.
    $this->withToken($token)->patchJson("{$base}/{$product['id']}", ['name' => 'Party jollof', 'option_group_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.name', 'Party jollof')
        ->assertJsonPath('data.option_groups', [])
        ->assertJsonPath('data.categories.0.name', 'Rice');

    $this->withToken($token)->putJson("{$base}/{$product['id']}/stock", ['stock_qty' => null])->assertOk()->assertJsonPath('data.stock_qty', null);
    $this->withToken($token)->putJson("{$base}/{$product['id']}/stock", ['stock_qty' => 0])->assertOk()->assertJsonPath('data.in_stock', false);
    expectApiError($this->withToken($token)->putJson("{$base}/{$product['id']}/stock", []), 400, 'validation_failed');
});

it('rejects links and images that belong to another store or vendor type', function () {
    $vendor = Vendor::factory()->create();
    $other = Vendor::factory()->ofType('pharmacy')->create();
    $token = staffToken($vendor);
    $pharmacyCategory = $other->vendorType->categories()->create(['name' => 'Drugs', 'slug' => 'drugs']);
    $theirMenu = $other->menus()->create(['name' => 'Theirs']);
    $theirGroup = $other->optionGroups()->create(['name' => 'Theirs']);

    $error = expectApiError($this->withToken($token)->postJson("/api/v1/vendor/stores/{$vendor->uuid}/products", [
        'name' => 'Sneaky', 'price_kobo' => 100,
        'category_ids' => [$pharmacyCategory->id],
        'menu_ids' => [$theirMenu->uuid],
        'option_group_ids' => [$theirGroup->uuid],
        'images' => ["vendors/{$other->uuid}/products/x.jpg", 'https://evil.example.com/x.jpg', "vendors/{$vendor->uuid}/products/../../x.jpg"],
        'vat_treatment' => 'free',
    ]), 400, 'validation_failed');

    expect($error['fields'])->toHaveKeys(['category_ids.0', 'menu_ids.0', 'option_group_ids.0', 'images.0', 'images.1', 'images.2', 'vat_treatment']);
    expect(Product::query()->count())->toBe(0);
});

it('caps the number of images', function () {
    $vendor = Vendor::factory()->create();
    config(['wegodeliva.catalogue.max_product_images' => 2]);
    $images = array_map(fn ($i) => "vendors/{$vendor->uuid}/products/{$i}.jpg", [1, 2, 3]);

    expectApiError($this->withToken(staffToken($vendor))->postJson("/api/v1/vendor/stores/{$vendor->uuid}/products", [
        'name' => 'Too many', 'price_kobo' => 100, 'images' => $images,
    ]), 400, 'validation_failed');
});

it('lists all products for the store, including inactive ones, and soft-deletes', function () {
    $vendor = Vendor::factory()->create();
    $token = staffToken($vendor);
    Product::factory()->for($vendor)->create(['name' => 'Visible']);
    $hidden = Product::factory()->for($vendor)->create(['name' => 'Hidden', 'is_active' => false]);
    Product::factory()->for(Vendor::factory())->create();
    $base = "/api/v1/vendor/stores/{$vendor->uuid}/products";

    $this->withToken($token)->getJson($base)->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($token)->getJson("{$base}?active=0")->assertJsonPath('data.*.name', ['Hidden']);
    $this->withToken($token)->getJson("{$base}?q=vis")->assertJsonPath('data.*.name', ['Visible']);

    $this->withToken($token)->deleteJson("{$base}/{$hidden->uuid}")->assertNoContent();
    expect(Product::query()->count())->toBe(2)->and(Product::withTrashed()->count())->toBe(3);
    expectApiError($this->withToken($token)->getJson("{$base}/{$hidden->uuid}"), 404, 'not_found');
});

<?php

use App\Enums\ClientApp;
use App\Enums\VendorStatus;
use App\Models\Favourite;
use App\Models\Product;
use App\Models\Vendor;

it('adds favourites idempotently and lists only what is still visible', function () {
    $user = makeUser();
    $token = tokenFor($user, ClientApp::Customer);
    $vendor = Vendor::factory()->create();
    $product = Product::factory()->for($vendor)->create();

    $this->withToken($token)->putJson("/api/v1/me/favourites/vendors/{$vendor->uuid}")->assertNoContent();
    $this->withToken($token)->putJson("/api/v1/me/favourites/vendors/{$vendor->uuid}")->assertNoContent();
    $this->withToken($token)->putJson("/api/v1/me/favourites/products/{$product->uuid}")->assertNoContent();
    expect(Favourite::query()->count())->toBe(2);

    $this->withToken($token)->getJson('/api/v1/me/favourites')
        ->assertOk()
        ->assertJsonPath('data.vendors.0.id', $vendor->uuid)
        ->assertJsonPath('data.products.0.id', $product->uuid);

    $product->update(['is_active' => false]);
    $vendor->forceFill(['status' => VendorStatus::Suspended])->save();
    $this->withToken($token)->getJson('/api/v1/me/favourites')
        ->assertJsonCount(0, 'data.vendors')
        ->assertJsonCount(0, 'data.products');
});

it('cannot favourite hidden vendors or products', function () {
    $token = tokenFor(makeUser(), ClientApp::Customer);
    $pending = Vendor::factory()->status(VendorStatus::PendingApproval)->create();
    $inactive = Product::factory()->for(Vendor::factory())->create(['is_active' => false]);

    expectApiError($this->withToken($token)->putJson("/api/v1/me/favourites/vendors/{$pending->uuid}"), 404, 'not_found');
    expectApiError($this->withToken($token)->putJson("/api/v1/me/favourites/products/{$inactive->uuid}"), 404, 'not_found');
});

it("removes only the caller's favourites", function () {
    $vendor = Vendor::factory()->create();
    $product = Product::factory()->for($vendor)->create();
    $mine = makeUser();
    $theirs = makeUser();
    foreach ([$mine, $theirs] as $user) {
        Favourite::query()->create(['user_id' => $user->id, 'vendor_id' => $vendor->id]);
        Favourite::query()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }

    $token = tokenFor($mine, ClientApp::Customer);
    $this->withToken($token)->deleteJson("/api/v1/me/favourites/vendors/{$vendor->uuid}")->assertNoContent();
    $this->withToken($token)->deleteJson("/api/v1/me/favourites/products/{$product->uuid}")->assertNoContent();

    expect(Favourite::query()->where('user_id', $mine->id)->count())->toBe(0)
        ->and(Favourite::query()->where('user_id', $theirs->id)->count())->toBe(2);
});

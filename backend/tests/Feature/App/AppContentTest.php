<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\AdminAuditLog;
use App\Models\Banner;
use App\Models\ContentPage;
use App\Models\VendorType;
use App\Services\Storage\StorageSigner;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStorage;

function banner(array $attributes = []): Banner
{
    return Banner::query()->create(array_merge(['placement' => 'home', 'title' => 'Free delivery', 'image_path' => 'banners/free.jpg'], $attributes));
}

beforeEach(function () {
    $this->seed(VendorTypeSeeder::class);
    $this->admin = makeUser(RoleName::Admin);
});

it('tells each app its versions, features, limits, support contacts and pages', function () {
    config(['wegodeliva.app.versions.customer.min' => '1.2.0', 'wegodeliva.app.support.whatsapp' => '+2348012345678', 'wegodeliva.payments.paystack' => true, 'wegodeliva.social.google_client_ids' => ['g']]);
    ContentPage::query()->forceCreate(['slug' => 'terms', 'title' => 'Terms', 'body' => '# Terms', 'is_published' => true]);
    ContentPage::query()->forceCreate(['slug' => 'draft', 'title' => 'Draft', 'body' => 'x', 'is_published' => false]);
    banner(['placement' => 'onboarding', 'title' => 'Order anything']);

    $this->getJson('/api/v1/app/settings?app=customer')->assertOk()
        ->assertJsonPath('data.app.min_version', '1.2.0')
        ->assertJsonPath('data.features.parcels', true)
        ->assertJsonPath('data.features.google_sign_in', true)
        ->assertJsonPath('data.features.apple_sign_in', false)
        ->assertJsonPath('data.payment_methods.paystack', true)
        ->assertJsonPath('data.limits.pay_on_delivery_max.amount_kobo', 2000000)
        ->assertJsonPath('data.support.whatsapp', '+2348012345678')
        ->assertJsonPath('data.pages', [['slug' => 'terms', 'title' => 'Terms']])
        ->assertJsonPath('data.onboarding.0.title', 'Order anything')
        ->assertJsonPath('data.onboarding.0.image_url', null);

    $this->getJson('/api/v1/app/settings?app=rider')->assertOk()->assertJsonPath('data.onboarding', []);
    expectApiError($this->getJson('/api/v1/app/settings'), 400, 'validation_failed');
});

it('serves published pages only', function () {
    ContentPage::query()->forceCreate(['slug' => 'privacy', 'title' => 'Privacy', 'body' => 'We keep little.', 'is_published' => true]);
    ContentPage::query()->forceCreate(['slug' => 'draft', 'title' => 'Draft', 'body' => 'x', 'is_published' => false]);

    $this->getJson('/api/v1/app/pages/privacy')->assertOk()->assertJsonPath('data.body', 'We keep little.');
    $this->getJson('/api/v1/app/pages/draft')->assertNotFound();
    $this->getJson('/api/v1/app/pages/missing')->assertNotFound();
});

it('shows live home banners with signed image URLs, per vendor type', function () {
    FakeStorage::fake();
    $food = VendorType::query()->where('slug', 'food')->sole();
    banner(['title' => 'Second', 'sort_order' => 2]);
    banner(['title' => 'First', 'sort_order' => 1]);
    banner(['title' => 'Off', 'is_active' => false]);
    banner(['title' => 'Later', 'starts_at' => now()->addDay()]);
    banner(['title' => 'Over', 'ends_at' => now()->subMinute()]);
    banner(['title' => 'Food only', 'vendor_type_id' => $food->id, 'image_path' => 'banners/food.jpg']);

    $this->getJson('/api/v1/app/banners')->assertOk()
        ->assertJsonPath('data.*.title', ['First', 'Second'])
        ->assertJsonPath('data.0.image_url', FakeStorage::readUrl('banners/free.jpg'))
        ->assertJsonMissingPath('data.0.image_path');
    $this->getJson('/api/v1/app/banners?vendor_type=food')->assertOk()->assertJsonPath('data.*.title', ['Food only']);
    $this->getJson('/api/v1/app/banners?vendor_type=nope')->assertOk()->assertJsonCount(0, 'data');

    // Signed URLs are cached: a second read makes no new call.
    $this->getJson('/api/v1/app/banners')->assertOk();
    Http::assertSentCount(2);
});

it('reads nothing when storage is not set up or refuses, and keeps going', function () {
    $signer = app(StorageSigner::class);
    config(['wegodeliva.storage.url' => null]);
    expect($signer->readUrls(['a.jpg', '', 'a.jpg']))->toBe(['a.jpg' => null])
        ->and($signer->readUrls([]))->toBe([])
        ->and($signer->readUrl(null))->toBeNull();

    FakeStorage::fake();
    FakeStorage::use(FakeStorage::BROKEN);
    expect($signer->readUrls(['b.jpg']))->toBe(['b.jpg' => null]);

    FakeStorage::use(FakeStorage::DOWN);
    expect($signer->readUrls(['c.jpg']))->toBe(['c.jpg' => null]);
});

it('lets admins manage pages and banners, audited', function () {
    FakeStorage::fake();
    $admin = fn () => $this->withToken(tokenFor($this->admin, ClientApp::Admin));

    $admin()->putJson('/api/v1/admin/pages/terms', ['title' => 'Terms of use', 'body' => '# Terms', 'is_published' => true])->assertCreated();
    $admin()->putJson('/api/v1/admin/pages/terms', ['title' => 'Terms of use', 'body' => '# Terms v2'])->assertOk();
    $admin()->putJson('/api/v1/admin/pages/Bad Slug', ['title' => 'x', 'body' => 'y'])->assertNotFound();
    $admin()->getJson('/api/v1/admin/pages')->assertOk()->assertJsonPath('data.0.body', '# Terms v2');

    $id = $admin()->postJson('/api/v1/admin/banners', ['placement' => 'home', 'title' => 'Launch', 'image_path' => 'banners/launch.png', 'link' => '/v/mama-put'])
        ->assertCreated()->assertJsonPath('data.image_path', 'banners/launch.png')->json('data.id');
    $admin()->patchJson("/api/v1/admin/banners/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $admin()->getJson('/api/v1/admin/banners')->assertOk()->assertJsonCount(1, 'data');
    expectApiError($admin()->postJson('/api/v1/admin/banners', ['placement' => 'home', 'title' => 'X', 'image_path' => '../secret.png']), 400, 'validation_failed');

    expect(AdminAuditLog::query()->pluck('action')->all())->toBe(['page.created', 'page.updated', 'banner.created', 'banner.updated']);
    app('auth')->forgetGuards();
    $this->withToken(tokenFor(makeUser(), ClientApp::Customer))->getJson('/api/v1/admin/banners')->assertForbidden();
});

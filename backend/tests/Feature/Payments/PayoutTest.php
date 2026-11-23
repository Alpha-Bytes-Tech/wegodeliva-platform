<?php

use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\RoleName;
use App\Models\AdminAuditLog;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WebhookEvent;
use App\Services\Ledger\EarningsLedger;
use App\Services\Payments\Gateways\FlutterwaveClient;
use App\Services\Payments\Gateways\PaystackClient;
use App\Services\Payments\Payouts;
use App\Services\Pricing\EarningsShare;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGateway;

beforeEach(function () {
    $this->gateway = new FakeGateway;
    app()->instance(PaystackClient::class, $this->gateway);
    $this->owner = makeUser(RoleName::VendorOwner);
    $this->owner->forceFill(['name' => 'Adaeze Okafor'])->save();
    $this->vendor = Vendor::factory()->create(['name' => 'Mama Put Kitchen']);
    $this->vendor->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->admin = makeUser(RoleName::Admin);
    $this->earnings = app(EarningsLedger::class);
    $this->account = $this->earnings->account(EarningsOwner::Vendor, $this->vendor->id);
    // ₦4,837.50 gross, ₦225 commission + ₦16.88 VAT: ₦4,595.62 available (spec 05 worked example).
    $this->earnings->recordOrderEarning($this->account, new EarningsShare(483750, 450000, 500, 22500, 1688), 'o1');
});

function api(User $user, ClientApp $app)
{
    app('auth')->forgetGuards();

    return test()->flushHeaders()->withToken(tokenFor($user, $app));
}

function storeUrl(string $path = ''): string
{
    return '/api/v1/vendor/stores/'.test()->vendor->uuid.$path;
}

function verifiedAccount(): PayoutAccount
{
    test()->gateway->accounts['058:0123456789'] = 'OKAFOR ADAEZE N';
    $id = api(test()->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), ['bank_code' => '058', 'account_number' => '0123456789'])->assertCreated()->json('data.id');

    return PayoutAccount::query()->where('uuid', $id)->sole();
}

function requestPayout(int $amount, PayoutAccount $bank, string $key = 'p1'): TestResponse
{
    return api(test()->owner, ClientApp::Vendor)->withHeader('Idempotency-Key', $key)->postJson(storeUrl('/payouts'), ['amount_kobo' => $amount, 'payout_account_id' => $bank->uuid]);
}

it('adds bank accounts with the name the bank reports, flagging names that do not match', function () {
    api($this->owner, ClientApp::Vendor)->getJson(storeUrl('/banks'))->assertOk()->assertJsonPath('data.0.name', 'GTBank');

    $verified = verifiedAccount();
    expect([$verified->account_name, $verified->status, $verified->is_default, $verified->bank_name])->toBe(['OKAFOR ADAEZE N', 'verified', true, 'GTBank']);

    $this->gateway->accounts['044:1111111111'] = 'MAMA PUT KITCHEN LTD';
    $this->gateway->accounts['044:2222222222'] = 'JOHN DOE';
    api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), ['bank_code' => '044', 'account_number' => '1111111111'])
        ->assertCreated()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.is_default', false);
    api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), ['bank_code' => '044', 'account_number' => '2222222222'])
        ->assertCreated()->assertJsonPath('data.status', 'needs_review')->assertJsonPath('data.account_number', '******2222');

    api($this->owner, ClientApp::Vendor)->getJson(storeUrl('/payout-accounts'))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $verified->uuid);
});

it('refuses bank accounts it cannot confirm', function (array $body, int $status, string $code) {
    verifiedAccount();

    expectApiError(api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), $body), $status, $code);
})->with([
    'unknown bank' => [['bank_code' => '999', 'account_number' => '0123456789'], 400, 'validation_failed'],
    'not 10 digits' => [['bank_code' => '058', 'account_number' => '12345'], 400, 'validation_failed'],
    'no such account' => [['bank_code' => '058', 'account_number' => '9999999999'], 422, 'bank_account_not_found'],
    'already saved' => [['bank_code' => '058', 'account_number' => '0123456789'], 409, 'bank_account_exists'],
]);

it('answers 503 when the gateway cannot check accounts', function () {
    $this->gateway->down = true;

    expectApiError(api($this->owner, ClientApp::Vendor)->getJson(storeUrl('/banks')), 503, 'gateway_unavailable');
    $this->gateway->down = false;
    api($this->owner, ClientApp::Vendor)->getJson(storeUrl('/banks'))->assertOk();
    $this->gateway->down = true;
    expectApiError(api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), ['bank_code' => '058', 'account_number' => '0123456789']), 503, 'gateway_unavailable');
});

it('keeps payouts to the store owner', function () {
    $staff = makeUser(RoleName::VendorStaff);
    $this->vendor->members()->attach($staff->id, ['role' => 'staff']);

    expectApiError(api($staff, ClientApp::Vendor)->getJson(storeUrl('/payouts')), 403, 'forbidden');
    expectApiError(api($staff, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), []), 403, 'forbidden');
    expectApiError(api(makeUser(RoleName::VendorOwner), ClientApp::Vendor)->getJson(storeUrl('/payout-accounts')), 404, 'not_found');
});

it('pays out: request holds, approval settles and transfers, the gateway result pays', function () {
    $bank = verifiedAccount();

    $id = requestPayout(400000, $bank)->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.account.bank_name', 'GTBank')->json('data.id');
    requestPayout(400000, $bank)->assertOk()->assertJsonPath('data.id', $id);
    expectApiError(requestPayout(1, $bank), 409, 'idempotency_key_reused');
    expect($this->account->fresh()->availableKobo())->toBe(59562);

    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'processing');
    $payout = Payout::query()->sole();
    $account = $this->account->fresh();
    expect($this->gateway->sent)->toBe([$payout->transfer_reference])
        ->and($payout->gateway_transfer_id)->toBe('TRF_1')
        ->and([$account->balance_kobo, $account->commission_due_kobo, $account->commission_vat_due_kobo, $account->held_kobo])->toBe([459562, 0, 0, 400000]);
    expectApiError(api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve"), 409, 'payout_status_conflict');

    // Still pending at the gateway: nothing changes.
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/sync")->assertOk()->assertJsonPath('data.status', 'processing');

    $this->gateway->transfers[$payout->transfer_reference] = 'success';
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/sync")->assertOk()->assertJsonPath('data.status', 'paid');
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/sync")->assertOk()->assertJsonPath('data.status', 'paid');

    $account = $this->account->fresh();
    expect([$account->balance_kobo, $account->held_kobo, $account->availableKobo()])->toBe([59562, 0, 59562])
        ->and(Payout::query()->sole()->paid_at)->not->toBeNull()
        ->and(AdminAuditLog::query()->where('action', 'payout.approved')->count())->toBe(1);
    api($this->owner, ClientApp::Vendor)->getJson(storeUrl('/payouts'))->assertOk()->assertJsonPath('data.0.status', 'paid');
});

it('releases the money when the transfer fails, keeping the commission settled', function () {
    $bank = verifiedAccount();
    $id = requestPayout(400000, $bank)->json('data.id');
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve");
    $this->gateway->transfers[Payout::query()->sole()->transfer_reference] = 'failed';

    $this->artisan('payouts:sync')->expectsOutput('Settled 0 payout(s).'); // not yet five minutes old
    Payout::query()->update(['updated_at' => now()->subMinutes(6)]);
    $this->artisan('payouts:sync')->expectsOutput('Settled 1 payout(s).');

    $account = $this->account->fresh();
    expect(Payout::query()->sole()->only(['status', 'failure_reason']))->toBe(['status' => 'failed', 'failure_reason' => 'transfer_failed'])
        ->and([$account->balance_kobo, $account->held_kobo, $account->commission_due_kobo, $account->availableKobo()])->toBe([459562, 0, 0, 459562])
        ->and(AdminAuditLog::query()->where('action', 'payout.transfer_failed')->count())->toBe(1);
});

it('keeps an approved payout when the gateway is down, and retries on the next approval', function () {
    $bank = verifiedAccount();
    $id = requestPayout(100000, $bank)->json('data.id');
    $this->gateway->down = true;

    expectApiError(api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve"), 503, 'gateway_unavailable');
    expect(Payout::query()->sole()->only(['status', 'failure_reason']))->toBe(['status' => 'approved', 'failure_reason' => 'gateway_unavailable']);

    Payout::query()->update(['status' => 'processing', 'updated_at' => now()->subMinutes(6)]);
    $this->artisan('payouts:sync')->expectsOutput('Settled 0 payout(s).'); // gateway still down: logged, skipped
    expectApiError(api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/sync"), 503, 'gateway_unavailable');
    Payout::query()->update(['status' => 'approved']);

    $this->gateway->down = false;
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'processing');
    expect(AdminAuditLog::query()->where('action', 'payout.approved')->count())->toBe(1);
});

it('lets admins reject a request, releasing the hold', function () {
    $bank = verifiedAccount();
    $id = requestPayout(100000, $bank)->json('data.id');

    expectApiError(api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/reject", []), 400, 'validation_failed');
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/reject", ['reason' => 'Account details under review'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.note', 'Account details under review');
    expectApiError(api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/reject", ['reason' => 'Again']), 409, 'payout_status_conflict');

    expect($this->account->fresh()->availableKobo())->toBe(459562);
    api($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/payouts?status=rejected')->assertOk()->assertJsonPath('data.0.id', $id);
});

it('refuses payouts below the minimum, above what is available, to unreviewed accounts, or when switched off', function () {
    $bank = verifiedAccount();
    $this->gateway->accounts['044:2222222222'] = 'JOHN DOE';
    $review = PayoutAccount::query()->where('uuid', api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payout-accounts'), ['bank_code' => '044', 'account_number' => '2222222222'])->json('data.id'))->sole();

    expectApiError(requestPayout(99999, $bank, 'a'), 422, 'payout_below_minimum');
    expectApiError(requestPayout(459563, $bank, 'b'), 422, 'insufficient_earnings');
    expectApiError(requestPayout(100000, $review, 'c'), 422, 'bank_account_unverified');
    expectApiError(api($this->owner, ClientApp::Vendor)->postJson(storeUrl('/payouts'), ['amount_kobo' => 100000, 'payout_account_id' => $bank->uuid]), 400, 'validation_failed');

    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payout-accounts/{$review->uuid}/approve")->assertOk()->assertJsonPath('data.status', 'verified');
    requestPayout(100000, $review->fresh(), 'd')->assertCreated();

    config(['wegodeliva.payouts.enabled' => false]);
    expectApiError(requestPayout(100000, $bank, 'e'), 422, 'payouts_disabled');
    expect(Payout::query()->count())->toBe(1);
});

it('removes bank accounts that have no payout in progress', function () {
    $bank = verifiedAccount();
    requestPayout(100000, $bank);

    expectApiError(api($this->owner, ClientApp::Vendor)->deleteJson(storeUrl("/payout-accounts/{$bank->uuid}")), 409, 'bank_account_in_use');
    Payout::query()->update(['status' => 'rejected']);
    api($this->owner, ClientApp::Vendor)->deleteJson(storeUrl("/payout-accounts/{$bank->uuid}"))->assertNoContent();
    expect(PayoutAccount::query()->count())->toBe(0)->and(PayoutAccount::withTrashed()->count())->toBe(1);
});

it('lets riders withdraw what is left after cash owed and commission', function () {
    $rider = makeUser(RoleName::Rider);
    $rider->forceFill(['name' => 'Tunde Bakare'])->save();
    $account = $this->earnings->account(EarningsOwner::Rider, $rider->id);
    $this->earnings->recordOrderEarning($account, new EarningsShare(500000, 500000, 300, 15000, 1125), 'o9');
    $this->earnings->collectCash($account, 200000, 'c9');
    $this->gateway->accounts['058:5555555555'] = 'BAKARE TUNDE';

    api($rider, ClientApp::Rider)->getJson('/api/v1/rider/banks')->assertOk();
    $bank = api($rider, ClientApp::Rider)->postJson('/api/v1/rider/payout-accounts', ['bank_code' => '058', 'account_number' => '5555555555'])->assertCreated()->assertJsonPath('data.status', 'verified')->json('data.id');
    api($rider, ClientApp::Rider)->getJson('/api/v1/rider/payout-accounts')->assertJsonCount(1, 'data');
    expectApiError(api($rider, ClientApp::Rider)->withHeader('Idempotency-Key', 'x')->postJson('/api/v1/rider/payouts', ['amount_kobo' => 283876, 'payout_account_id' => $bank]), 422, 'insufficient_earnings');
    $id = api($rider, ClientApp::Rider)->withHeader('Idempotency-Key', 'y')->postJson('/api/v1/rider/payouts', ['amount_kobo' => 283875, 'payout_account_id' => $bank])->assertCreated()->json('data.id');

    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve")->assertOk();
    $account = $account->fresh();
    // Settlement takes commission, its VAT and the ₦2,000 cash owed out of the balance.
    expect([$account->balance_kobo, $account->cash_owed_kobo, $account->held_kobo])->toBe([283875, 0, 283875]);
    api($rider, ClientApp::Rider)->getJson('/api/v1/rider/payouts')->assertJsonPath('data.0.status', 'processing');
    api($rider, ClientApp::Rider)->deleteJson("/api/v1/rider/payout-accounts/{$bank}")->assertStatus(409);
});

it('settles payouts when the gateway sends a signed transfer webhook', function () {
    $bank = verifiedAccount();
    $id = requestPayout(100000, $bank)->json('data.id');
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve");
    $reference = Payout::query()->sole()->transfer_reference;

    // The real client checks the signature and asks Paystack how the transfer went.
    app()->forgetInstance(PaystackClient::class);
    config(['services.paystack' => ['base_url' => 'https://paystack.test', 'secret_key' => 'sk_hook']]);
    Http::fake(['paystack.test/transfer/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);
    $body = json_encode(['event' => 'transfer.success', 'data' => ['id' => 55, 'reference' => $reference]]);
    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_hook')], $body)->assertOk();

    expect(Payout::query()->sole()->status)->toBe('paid')->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('keeps payout review to admins', function () {
    $bank = verifiedAccount();
    $id = requestPayout(100000, $bank)->json('data.id');
    $zone = makeUser(RoleName::ZoneAdmin);

    expectApiError(api($zone, ClientApp::Admin)->getJson('/api/v1/admin/payouts'), 403, 'forbidden');
    expectApiError(api($zone, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve"), 403, 'forbidden');
    expectApiError(api($zone, ClientApp::Admin)->postJson("/api/v1/admin/payout-accounts/{$bank->uuid}/approve"), 403, 'forbidden');
    expect(Payout::query()->sole()->status)->toBe('requested');
});

it('matches bank names sensibly', function (string $bank, array $names, bool $matches) {
    expect(Payouts::namesMatch($bank, $names))->toBe($matches);
})->with([
    ['ADEBAYO OLUWASEUN JOHN', ['Seun Adebayo'], true],
    ['MAMA PUT KITCHEN LTD', ['Ada Okafor', 'Mama Put Kitchen'], true],
    ['JOHN DOE', ['Ada Okafor'], false],
    ['A B', ['A B'], false],
    ['OKAFOR', [], false],
]);

it('can pay out through Flutterwave instead', function () {
    $flutterwave = new FakeGateway;
    app()->instance(FlutterwaveClient::class, $flutterwave);
    config(['wegodeliva.payouts.gateway' => 'flutterwave']);

    expect(app(Payouts::class)->banks())->toBe($flutterwave->banks());
});

it('settles a payout once, even from a stale copy', function () {
    $bank = verifiedAccount();
    $id = requestPayout(100000, $bank)->json('data.id');
    api($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/payouts/{$id}/approve");
    $stale = Payout::query()->sole();
    $this->gateway->transfers[$stale->transfer_reference] = 'success';
    app(Payouts::class)->sync(Payout::query()->sole());

    expect(app(Payouts::class)->sync($stale)->status)->toBe('paid')
        ->and($this->account->fresh()->balance_kobo)->toBe(483750 - 22500 - 1688 - 100000);
});

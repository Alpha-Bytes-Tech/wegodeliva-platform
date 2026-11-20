<?php

use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Models\AdminAuditLog;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\WalletLedger;
use App\Services\Pricing\EarningsShare;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->wallets = app(WalletLedger::class);
    $this->earnings = app(EarningsLedger::class);
    $this->admin = makeUser(RoleName::Admin);
    $this->adminToken = tokenFor($this->admin, ClientApp::Admin);
});

it('shows customers their wallet, limits and history, newest first', function () {
    $customer = makeUser();
    $this->wallets->credit($customer, 1_000_000, WalletKind::TopUp, 't1');
    $this->wallets->debit($customer, 250_000, WalletKind::OrderPayment, 'o1');
    $token = tokenFor($customer, ClientApp::Customer);

    $this->withToken($token)->getJson('/api/v1/me/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance.amount_kobo', 750_000)
        ->assertJsonPath('data.balance.formatted', '₦7,500.00')
        ->assertJsonPath('data.kyc_tier', 1)
        ->assertJsonPath('data.limits.daily_topup.amount_kobo', 5_000_000)
        ->assertJsonPath('data.limits.max_balance.amount_kobo', 30_000_000)
        // ₦50,000 a day minus today's ₦10,000 top-up.
        ->assertJsonPath('data.limits.topup_available.amount_kobo', 4_000_000);

    $this->withToken($token)->getJson('/api/v1/me/wallet/transactions?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'order_payment')
        ->assertJsonPath('data.0.label', 'Order payment')
        ->assertJsonPath('data.0.direction', 'debit')
        ->assertJsonPath('data.0.balance_after.amount_kobo', 750_000)
        ->assertJsonMissingPath('data.0.reference')
        ->assertJsonCount(1, 'data');
});

it('limits the top-up allowance by the room left under the cap, with no cap at tier 3', function () {
    $customer = makeUser();
    $this->wallets->adjust($customer, $this->admin, 29_500_000, 'Test', 'a1');
    $token = tokenFor($customer, ClientApp::Customer);

    $this->withToken($token)->getJson('/api/v1/me/wallet')->assertJsonPath('data.limits.topup_available.amount_kobo', 500_000);

    $customer->wallet()->update(['kyc_tier' => 3]);
    $this->withToken($token)->getJson('/api/v1/me/wallet')
        ->assertJsonPath('data.limits.max_balance', null)
        ->assertJsonPath('data.limits.topup_available.amount_kobo', 500_000_000);
});

it('shows a store\'s earnings to its owner and staff only', function () {
    $vendor = Vendor::factory()->create();
    $this->earnings->recordOrderEarning($this->earnings->account(EarningsOwner::Vendor, $vendor->id), new EarningsShare(483750, 450000, 500, 22500, 1688), 'o1');
    $staff = makeUser(RoleName::VendorStaff);
    $vendor->members()->attach($staff->id, ['role' => 'staff']);
    $outsider = makeUser(RoleName::VendorOwner);

    $this->withToken(tokenFor($staff, ClientApp::Vendor))->getJson("/api/v1/vendor/stores/{$vendor->uuid}/earnings")
        ->assertOk()
        ->assertJsonPath('data.gross_earnings.amount_kobo', 483750)
        ->assertJsonPath('data.commission_due.amount_kobo', 22500)
        ->assertJsonPath('data.commission_vat_due.amount_kobo', 1688)
        ->assertJsonPath('data.available_to_withdraw.formatted', '₦4,595.62')
        ->assertJsonMissingPath('data.cash_owed');
    $this->withToken(tokenFor($staff, ClientApp::Vendor))->getJson("/api/v1/vendor/stores/{$vendor->uuid}/earnings/entries")
        ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.kind', 'commission_vat_accrued');

    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken(tokenFor($outsider, ClientApp::Vendor))->getJson("/api/v1/vendor/stores/{$vendor->uuid}/earnings"), 404, 'not_found');
});

it('shows riders their own earnings and cash owed', function () {
    $rider = makeUser(RoleName::Rider);
    $account = $this->earnings->account(EarningsOwner::Rider, $rider->id);
    $this->earnings->recordOrderEarning($account, new EarningsShare(120000, 100000, 300, 3000, 225), 'o1');
    $this->earnings->collectCash($account, 20000, 'c1');
    $token = tokenFor($rider, ClientApp::Rider);

    $this->withToken($token)->getJson('/api/v1/rider/earnings')
        ->assertOk()
        ->assertJsonPath('data.cash_owed.amount_kobo', 20000)
        ->assertJsonPath('data.available_to_withdraw.amount_kobo', 120000 - 3225 - 20000);
    $this->withToken($token)->getJson('/api/v1/rider/earnings/entries')->assertOk()->assertJsonCount(4, 'data');

    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken(tokenFor(makeUser(), ClientApp::Customer))->getJson('/api/v1/rider/earnings'), 403, 'forbidden');
});

it('lets admins adjust a wallet once per idempotency key, audited', function () {
    $customer = makeUser();
    $key = (string) Str::uuid();
    $url = "/api/v1/admin/users/{$customer->uuid}/wallet/adjustments";

    $this->withToken($this->adminToken)->postJson($url, ['amount_kobo' => 150000, 'reason' => 'Late delivery goodwill', 'idempotency_key' => $key])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Adjustment')
        ->assertJsonPath('data.reference', "admin-adjust:{$key}")
        ->assertJsonPath('data.created_by', $this->admin->id);
    $this->withToken($this->adminToken)->postJson($url, ['amount_kobo' => 150000, 'reason' => 'Late delivery goodwill', 'idempotency_key' => $key])
        ->assertOk();
    expectApiError($this->withToken($this->adminToken)->postJson($url, ['amount_kobo' => 999, 'reason' => 'Changed my mind', 'idempotency_key' => $key]), 409, 'conflict');

    expect(WalletTransaction::query()->count())->toBe(1)
        ->and(AdminAuditLog::query()->where('action', 'wallet.adjusted')->sole()->after)->toMatchArray(['amount_kobo' => 150000, 'balance_after_kobo' => 150000]);

    $this->withToken($this->adminToken)->getJson("/api/v1/admin/users/{$customer->uuid}/wallet")->assertOk()->assertJsonPath('data.balance.amount_kobo', 150000);
    $this->withToken($this->adminToken)->getJson("/api/v1/admin/users/{$customer->uuid}/wallet/transactions")
        ->assertOk()->assertJsonPath('data.0.note', 'Late delivery goodwill')->assertJsonPath('data.0.reference', "admin-adjust:{$key}");
});

it('validates adjustments', function (array $payload, string $field) {
    $customer = makeUser();
    $error = expectApiError($this->withToken($this->adminToken)->postJson("/api/v1/admin/users/{$customer->uuid}/wallet/adjustments", $payload + ['amount_kobo' => 100, 'reason' => 'Valid reason', 'idempotency_key' => (string) Str::uuid()]), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey($field);
})->with([
    'zero' => [['amount_kobo' => 0], 'amount_kobo'],
    'too large' => [['amount_kobo' => 100000001], 'amount_kobo'],
    'no reason' => [['reason' => ''], 'reason'],
    'bad key' => [['idempotency_key' => 'abc'], 'idempotency_key'],
]);

it('lets admins view and adjust vendor and rider earnings and record cash remittances', function () {
    $vendor = Vendor::factory()->create();
    $rider = makeUser(RoleName::Rider);
    $this->earnings->collectCash($this->earnings->account(EarningsOwner::Rider, $rider->id), 30000, 'c1');
    $key = (string) Str::uuid();

    $this->withToken($this->adminToken)->postJson("/api/v1/admin/vendors/{$vendor->uuid}/earnings/adjustments", ['amount_kobo' => -5000, 'reason' => 'Damaged packaging charge', 'idempotency_key' => $key])
        ->assertOk()->assertJsonPath('data.gross_earnings.amount_kobo', -5000);
    $this->withToken($this->adminToken)->postJson("/api/v1/admin/vendors/{$vendor->uuid}/earnings/adjustments", ['amount_kobo' => -5000, 'reason' => 'Damaged packaging charge', 'idempotency_key' => $key])
        ->assertOk()->assertJsonPath('data.gross_earnings.amount_kobo', -5000);
    expectApiError($this->withToken($this->adminToken)->postJson("/api/v1/admin/vendors/{$vendor->uuid}/earnings/adjustments", ['amount_kobo' => -6000, 'reason' => 'Different', 'idempotency_key' => $key]), 409, 'conflict');
    $this->withToken($this->adminToken)->getJson("/api/v1/admin/vendors/{$vendor->uuid}/earnings")->assertOk()->assertJsonPath('data.available_to_withdraw.amount_kobo', 0);
    $this->withToken($this->adminToken)->getJson("/api/v1/admin/vendors/{$vendor->uuid}/earnings/entries")->assertOk()->assertJsonPath('data.0.note', 'Damaged packaging charge');

    $remit = ['amount_kobo' => 20000, 'note' => 'Bank transfer', 'idempotency_key' => (string) Str::uuid()];
    $this->withToken($this->adminToken)->postJson("/api/v1/admin/riders/{$rider->uuid}/cash-remittances", $remit)->assertOk()->assertJsonPath('data.cash_owed.amount_kobo', 10000);
    $this->withToken($this->adminToken)->postJson("/api/v1/admin/riders/{$rider->uuid}/cash-remittances", $remit)->assertOk()->assertJsonPath('data.cash_owed.amount_kobo', 10000);
    expectApiError($this->withToken($this->adminToken)->postJson("/api/v1/admin/riders/{$rider->uuid}/cash-remittances", ['amount_kobo' => 10001, 'idempotency_key' => (string) Str::uuid()]), 422, 'remittance_exceeds_cash_owed');

    $this->withToken($this->adminToken)->postJson("/api/v1/admin/riders/{$rider->uuid}/earnings/adjustments", ['amount_kobo' => 5000, 'reason' => 'Bonus', 'idempotency_key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.gross_earnings.amount_kobo', 5000);
    $this->withToken($this->adminToken)->getJson("/api/v1/admin/riders/{$rider->uuid}/earnings")->assertOk()->assertJsonPath('data.cash_owed.amount_kobo', 10000);
    $this->withToken($this->adminToken)->getJson("/api/v1/admin/riders/{$rider->uuid}/earnings/entries")->assertOk()->assertJsonCount(3, 'data');

    expect(AdminAuditLog::query()->whereIn('action', ['earnings.adjusted', 'rider.cash_remitted'])->count())->toBe(3);
    // A user who is not a rider has no rider earnings.
    expectApiError($this->withToken($this->adminToken)->getJson('/api/v1/admin/riders/'.makeUser()->uuid.'/earnings'), 404, 'not_found');
});

it('keeps wallets and earnings away from zone admins', function () {
    $zoneAdmin = tokenFor(makeUser(RoleName::ZoneAdmin), ClientApp::Admin);
    $customer = makeUser();

    expectApiError($this->withToken($zoneAdmin)->getJson("/api/v1/admin/users/{$customer->uuid}/wallet"), 403, 'forbidden');
    expectApiError($this->withToken($zoneAdmin)->postJson("/api/v1/admin/users/{$customer->uuid}/wallet/adjustments", ['amount_kobo' => 100, 'reason' => 'Nope', 'idempotency_key' => (string) Str::uuid()]), 403, 'forbidden');
    expect(WalletTransaction::query()->count())->toBe(0);
});

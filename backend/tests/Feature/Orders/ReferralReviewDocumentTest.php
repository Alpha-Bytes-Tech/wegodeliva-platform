<?php

use App\Enums\ActorRole;
use App\Enums\ClientApp;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Models\AdminAuditLog;
use App\Models\Document;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Services\Ledger\WalletLedger;
use App\Services\Orders\OrderFulfilment;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\Referrals;
use App\Services\Orders\Refunds;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor, ['price_kobo' => 2_000_000]); // ₦20,000
    $this->admin = makeUser(RoleName::Admin);
    $this->wallets = app(WalletLedger::class);
});

function client(User $user, ClientApp $app)
{
    app('auth')->forgetGuards();

    return test()->flushHeaders()->withToken(tokenFor($user, $app));
}

/** A customer signed up with $code, with a saved address. */
function signUp(?string $code, string $email = 'new@example.com'): User
{
    test()->postJson('/api/v1/auth/register', [
        'name' => 'Funmi Ade', 'email' => $email, 'phone' => '0803'.random_int(1000000, 9999999),
        'password' => 'secret123', 'accept_terms' => true, 'referral_code' => $code,
    ])->assertCreated();
    $user = User::query()->where('email', $email)->sole();
    $user->forceFill(['email_verified_at' => now()])->save();
    Checkout::address($user);

    return $user;
}

/** Places a wallet-paid order of $qty × ₦20,000 for $user and has support deliver it. */
function deliveredOrder(User $user, int $qty = 1, string $key = 'k1'): Order
{
    $t = test();
    app(WalletLedger::class)->adjust($user, $t->admin, 5_000_000 * $qty, 'Funds', "fund-{$key}");
    $token = Checkout::token($user);
    app('auth')->forgetGuards();
    $quote = Checkout::quote($token, Checkout::cart($t->vendor, [[$t->product, $qty]], $user->addresses()->first()))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $order = Order::query()->where('code', Checkout::place($token, $quote, 'wallet', $key)->assertCreated()->json('data.code'))->sole();

    $machine = app(OrderStateMachine::class);
    $machine->transition($order, OrderStatus::Accepted, ActorRole::Vendor);
    $machine->transition($order, OrderStatus::Ready, ActorRole::Vendor);
    $machine->transition($order, OrderStatus::PickedUp, ActorRole::Admin);
    app(OrderFulfilment::class)->adminDeliver($order, $t->admin, 'Test delivery', null);

    return $order->refresh();
}

describe('referrals', function () {
    it('rewards the referrer 5% of the first purchase, once, when it is delivered', function () {
        $referrer = makeUser();
        $friend = signUp(strtolower($referrer->referral_code));
        $referral = Referral::query()->sole();
        expect($referral->status)->toBe('pending')->and($friend->referred_by_user_id)->toBe($referrer->id);

        deliveredOrder($friend);
        // 5% of ₦20,000 = ₦1,000 (delivery fee, fees, VAT and tip excluded).
        expect($referral->fresh()->only(['status', 'reward_kobo']))->toBe(['status' => 'rewarded', 'reward_kobo' => 100000])
            ->and($this->wallets->wallet($referrer)->transactions()->sole()->kind)->toBe(WalletKind::ReferralReward);

        deliveredOrder($friend, key: 'k2');
        expect($this->wallets->wallet($referrer)->balance_kobo)->toBe(100000);

        client($referrer, ClientApp::Customer)->getJson('/api/v1/me/referrals')->assertOk()
            ->assertJsonPath('data.code', $referrer->referral_code)
            ->assertJsonPath('data.share_url', 'https://wegodeliva.com/r/'.$referrer->referral_code)
            ->assertJsonPath('data.earned.amount_kobo', 100000)
            ->assertJsonPath('data.referrals.0.name', 'Funmi')
            ->assertJsonPath('data.referrals.0.status', 'rewarded');
    });

    it('caps the reward at ₦5,000', function () {
        $referrer = makeUser();
        $friend = signUp($referrer->referral_code);

        deliveredOrder($friend, qty: 6); // ₦120,000 × 5% = ₦6,000 → ₦5,000

        expect(Referral::query()->sole()->reward_kobo)->toBe(500000);
    });

    it('takes the reward back when the first order is fully refunded within 7 days, and counts the next one', function () {
        $referrer = makeUser();
        $friend = signUp($referrer->referral_code);
        $first = deliveredOrder($friend);
        $refunds = app(Refunds::class);

        $refunds->refund($first, 1_000_000, 'Part of the order missing', Fault::Vendor, $this->admin, 'r1');
        expect(Referral::query()->sole()->status)->toBe('rewarded'); // partial: reward stays

        $refunds->refund($first, $first->fresh()->refundableKobo(), 'Rest refunded', Fault::Vendor, $this->admin, 'r2');
        expect(Referral::query()->sole()->only(['status', 'reward_kobo', 'qualifying_order_id']))->toBe(['status' => 'pending', 'reward_kobo' => 0, 'qualifying_order_id' => null])
            ->and($this->wallets->wallet($referrer)->balance_kobo)->toBe(0)
            ->and(AdminAuditLog::query()->where('action', 'referral.clawed_back')->count())->toBe(1);

        deliveredOrder($friend, key: 'k2');
        expect(Referral::query()->sole()->status)->toBe('rewarded')->and($this->wallets->wallet($referrer)->balance_kobo)->toBe(100000);
    });

    it('keeps the reward after the clawback window, and takes back only what the wallet holds', function () {
        $referrer = makeUser();
        $friend = signUp($referrer->referral_code);
        $order = deliveredOrder($friend);

        Carbon::setTestNow(now()->addDays(8));
        app(Refunds::class)->refund($order, $order->refundableKobo(), 'Late refund', Fault::Vendor, $this->admin, 'r1');
        expect(Referral::query()->sole()->status)->toBe('rewarded');
        Carbon::setTestNow();

        $this->wallets->debit($referrer, 60000, WalletKind::OrderPayment, 'spent');
        client($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/referrals/'.Referral::query()->sole()->uuid.'/void', ['reason' => 'Same household'])
            ->assertOk()->assertJsonPath('data.status', 'void');
        expect($this->wallets->wallet($referrer)->balance_kobo)->toBe(0)
            ->and(AdminAuditLog::query()->where('action', 'referral.clawed_back')->sole()->after['taken_kobo'])->toBe(40000);
    });

    it('ignores unknown codes, self-referral and switched-off referrals', function () {
        signUp('NOPE1234', 'a@example.com');
        expect(Referral::query()->count())->toBe(0);

        config(['wegodeliva.referrals.enabled' => false]);
        signUp(makeUser()->referral_code, 'b@example.com');
        expect(Referral::query()->count())->toBe(0);

        config(['wegodeliva.referrals.enabled' => true]);
        $user = makeUser();
        expect(app(Referrals::class)->recordSignUp($user, $user->referral_code))->toBeNull()
            ->and(app(Referrals::class)->recordSignUp($user, null))->toBeNull();

        expectApiError(client(makeUser(RoleName::ZoneAdmin), ClientApp::Admin)->postJson('/api/v1/admin/referrals/'.Str::uuid().'/void', ['reason' => 'x x x']), 404, 'not_found');
    });
});

describe('reviews', function () {
    it('lets the customer rate the store, rider and products of a delivered order, once', function () {
        $customer = signUp(null);
        $rider = makeUser();
        $rider->riderProfile()->forceCreate(['rider_type' => 'independent', 'approved_at' => now()]);
        $order = deliveredOrder($customer);
        $order->forceFill(['rider_id' => $rider->id])->save();
        $url = "/api/v1/me/orders/{$order->code}/reviews";

        client($customer, ClientApp::Customer)->postJson($url, [
            'vendor' => ['rating' => 4, 'comment' => 'Tasty'],
            'rider' => ['rating' => 5],
            'products' => [['product_id' => $this->product->uuid, 'rating' => 3]],
        ])->assertCreated()->assertJsonCount(3, 'data');

        expect($this->vendor->fresh()->only(['rating_avg', 'rating_count']))->toBe(['rating_avg' => '4.00', 'rating_count' => 1])
            ->and($this->product->fresh()->rating_avg)->toBe('3.00')
            ->and((float) $rider->riderProfile()->value('rating_avg'))->toBe(5.0);

        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['vendor' => ['rating' => 1]]), 409, 'already_reviewed');

        app('auth')->forgetGuards();
        $this->getJson("/api/v1/catalogue/vendors/{$this->vendor->slug}/reviews")->assertOk()
            ->assertJsonPath('data.0.rating', 4)
            ->assertJsonPath('data.0.author', 'Funmi')
            ->assertJsonMissingPath('data.0.is_visible');
    });

    it('checks what can be rated', function () {
        $customer = signUp(null);
        $order = deliveredOrder($customer);
        $url = "/api/v1/me/orders/{$order->code}/reviews";

        expectApiError(client($customer, ClientApp::Customer)->postJson($url, []), 400, 'validation_failed');
        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['vendor' => ['rating' => 6]]), 400, 'validation_failed');
        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['rider' => ['rating' => 5]]), 400, 'validation_failed');
        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['products' => [['product_id' => Checkout::product($this->vendor)->uuid, 'rating' => 5]]]), 400, 'validation_failed');
        expectApiError(client(makeUser(), ClientApp::Customer)->postJson($url, ['vendor' => ['rating' => 5]]), 404, 'not_found');

        Carbon::setTestNow(now()->addDays(31));
        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['vendor' => ['rating' => 5]]), 422, 'review_window_closed');
        Carbon::setTestNow();

        $order->forceFill(['status' => 'cancelled'])->save();
        expectApiError(client($customer, ClientApp::Customer)->postJson($url, ['vendor' => ['rating' => 5]]), 409, 'order_not_reviewable');
    });

    it('lets admins hide a review, which drops it from the store\'s rating', function () {
        $customer = signUp(null);
        $order = deliveredOrder($customer);
        client($customer, ClientApp::Customer)->postJson("/api/v1/me/orders/{$order->code}/reviews", ['vendor' => ['rating' => 1, 'comment' => 'Rude words']])->assertCreated();
        $id = client($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/reviews?subject=vendor')->assertOk()->assertJsonPath('data.0.is_visible', true)->json('data.0.id');

        client($this->admin, ClientApp::Admin)->patchJson("/api/v1/admin/reviews/{$id}", ['is_visible' => false])->assertOk()->assertJsonPath('data.is_visible', false);
        expect($this->vendor->fresh()->only(['rating_avg', 'rating_count']))->toBe(['rating_avg' => '0.00', 'rating_count' => 0]);
        client($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/reviews?hidden=1')->assertJsonPath('data.0.id', $id);

        $owner = makeUser(RoleName::VendorOwner);
        $this->vendor->members()->attach($owner->id, ['role' => 'owner']);
        client($owner, ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$this->vendor->uuid}/reviews")->assertOk()->assertJsonPath('data', []);
        expectApiError(client(makeUser(RoleName::VendorOwner), ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$this->vendor->uuid}/reviews"), 404, 'not_found');

        $this->vendor->forceFill(['status' => 'suspended'])->save();
        app('auth')->forgetGuards();
        expectApiError($this->getJson("/api/v1/catalogue/vendors/{$this->vendor->slug}/reviews"), 404, 'not_found');
    });
});

describe('documents', function () {
    it('lets the store owner submit documents for an admin to approve or reject', function () {
        $owner = makeUser(RoleName::VendorOwner);
        $this->vendor->members()->attach($owner->id, ['role' => 'owner']);
        $base = "/api/v1/vendor/stores/{$this->vendor->uuid}/documents";
        $path = "vendors/{$this->vendor->uuid}/documents/cac.pdf";

        expectApiError(client($owner, ClientApp::Vendor)->postJson($base, ['kind' => 'cac_certificate', 'file_path' => 'vendors/other/cac.pdf']), 400, 'validation_failed');
        expectApiError(client($owner, ClientApp::Vendor)->postJson($base, ['kind' => 'drivers_licence', 'file_path' => $path]), 400, 'validation_failed');
        $id = client($owner, ClientApp::Vendor)->postJson($base, ['kind' => 'cac_certificate', 'file_path' => $path])->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');

        $review = fn (array $body) => client($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/documents/{$id}/review", $body);
        expectApiError($review(['decision' => 'reject']), 400, 'validation_failed');
        $review(['decision' => 'reject', 'note' => 'Blurry scan'])->assertOk()->assertJsonPath('data.status', 'rejected');
        expectApiError($review(['decision' => 'approve']), 409, 'document_status_conflict');

        // Resubmitting replaces the rejected one.
        client($owner, ClientApp::Vendor)->postJson($base, ['kind' => 'cac_certificate', 'file_path' => "vendors/{$this->vendor->uuid}/documents/cac-2.pdf"])->assertCreated()->assertJsonPath('data.id', $id);
        $review(['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        client($owner, ClientApp::Vendor)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        expect(AdminAuditLog::query()->whereIn('action', ['document.approved', 'document.rejected'])->count())->toBe(2);

        $staff = makeUser(RoleName::VendorStaff);
        $this->vendor->members()->attach($staff->id, ['role' => 'staff']);
        expectApiError(client($staff, ClientApp::Vendor)->getJson($base), 403, 'forbidden');
        expectApiError(client(makeUser(RoleName::VendorOwner), ClientApp::Vendor)->getJson($base), 404, 'not_found');
    });

    it('lets admins ask for a document, which the rider then submits', function () {
        $rider = makeUser(RoleName::Rider);
        $rider->riderProfile()->forceCreate(['rider_type' => 'independent', 'approved_at' => now()]);
        $ask = fn (array $body) => client($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/documents', $body);

        expectApiError($ask(['owner_type' => 'rider', 'owner_id' => $rider->uuid, 'kind' => 'cac_certificate']), 400, 'validation_failed');
        expectApiError($ask(['owner_type' => 'rider', 'owner_id' => makeUser()->uuid, 'kind' => 'drivers_licence']), 404, 'not_found');
        $ask(['owner_type' => 'rider', 'owner_id' => $rider->uuid, 'kind' => 'drivers_licence', 'note' => 'Please upload both sides'])
            ->assertCreated()->assertJsonPath('data.status', 'requested');
        $ask(['owner_type' => 'vendor', 'owner_id' => $this->vendor->uuid, 'kind' => 'pharmacy_licence'])->assertCreated();

        client($rider, ClientApp::Rider)->getJson('/api/v1/rider/documents')->assertJsonPath('data.0.review_note', 'Please upload both sides');
        client($rider, ClientApp::Rider)->postJson('/api/v1/rider/documents', ['kind' => 'drivers_licence', 'file_path' => "riders/{$rider->uuid}/documents/licence.jpg"])
            ->assertCreated()->assertJsonPath('data.status', 'submitted');

        expect(Document::query()->count())->toBe(2);
        client($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/documents?status=submitted')->assertOk()->assertJsonCount(1, 'data');
        expectApiError(client(makeUser(RoleName::ZoneAdmin), ClientApp::Admin)->getJson('/api/v1/admin/documents'), 403, 'forbidden');
    });
});

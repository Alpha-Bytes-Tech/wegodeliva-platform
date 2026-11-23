<?php

use App\Enums\EarningsOwner;
use App\Enums\OrderStatus;
use App\Enums\WalletKind;
use App\Jobs\ProcessWebhookEvent;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationReport;
use App\Models\WalletTopup;
use App\Models\WebhookEvent;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\WalletLedger;
use App\Services\Payments\Gateways\FlutterwaveClient;
use App\Services\Payments\Gateways\PaystackClient;
use App\Services\Pricing\EarningsShare;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\Checkout;
use Tests\Support\FakeGateway;

beforeEach(function () {
    config(['wegodeliva.payments.paystack' => true, 'wegodeliva.payments.flutterwave' => true]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->wallets = app(WalletLedger::class);
});

function topUp(int $amount, string $key = 't1', string $gateway = 'flutterwave'): TestResponse
{
    return test()->withToken(test()->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/me/wallet/topups', ['amount_kobo' => $amount, 'gateway' => $gateway]);
}

describe('top-ups', function () {
    beforeEach(function () {
        $this->gateway = new FakeGateway;
        app()->instance(FlutterwaveClient::class, $this->gateway);
    });

    it('credits the wallet only after the gateway confirms the payment', function () {
        $response = topUp(500000)->assertCreated()->assertJsonPath('data.status', 'pending');
        $payment = Payment::query()->sole();
        expect($response->json('data.topup_id'))->toBe(WalletTopup::query()->sole()->uuid)
            ->and($this->wallets->wallet($this->customer)->balance_kobo)->toBe(0);

        topUp(500000)->assertOk()->assertJsonPath('data.reference', $payment->reference);
        expectApiError(topUp(600000), 409, 'idempotency_key_reused');

        $this->gateway->pays($payment);
        $this->withToken($this->token)->postJson("/api/v1/me/payments/{$payment->reference}/verify")
            ->assertOk()->assertJsonPath('data.topup.status', 'succeeded');

        $credit = $this->wallets->wallet($this->customer)->transactions()->sole();
        expect([$credit->kind, $credit->amount_kobo, $credit->gateway, $credit->reference])->toBe([WalletKind::TopUp, 500000, 'flutterwave', 'topup:'.WalletTopup::query()->sole()->uuid])
            ->and($this->wallets->topUpsToday($this->wallets->wallet($this->customer)))->toBe(500000);
    });

    it('enforces the minimum, the daily limit and the balance cap, counting top-ups in flight', function () {
        $error = expectApiError(topUp(9999), 422, 'topup_below_minimum');
        expect($error['fields']['amount_kobo'][0])->toBe('The minimum top-up is ₦100.00.');

        topUp(3_000_000, 'a')->assertCreated();
        $error = expectApiError(topUp(2_000_001, 'b'), 422, 'topup_daily_limit');
        expect($error['meta'])->toMatchArray(['available' => ['amount_kobo' => 2_000_000, 'formatted' => '₦20,000.00'], 'kyc_tier' => 1]);
        topUp(2_000_000, 'c')->assertCreated();

        // With nothing in flight, the tier 1 cap of ₦300,000 is what stops this one.
        WalletTopup::query()->update(['status' => 'failed']);
        $this->wallets->adjust($this->customer, $this->customer, 29_000_000, 'Test', 'adj');
        $error = expectApiError(topUp(1_000_001, 'd'), 422, 'wallet_balance_cap');
        expect($error['meta']['room']['amount_kobo'])->toBe(1_000_000);
        topUp(1_000_000, 'e')->assertCreated();
    });

    it('refuses top-ups when switched off, and validates the request', function () {
        expectApiError($this->withToken($this->token)->postJson('/api/v1/me/wallet/topups', ['amount_kobo' => 10000, 'gateway' => 'paystack']), 400, 'validation_failed');
        expectApiError($this->withToken($this->token)->withHeader('Idempotency-Key', 'x')->postJson('/api/v1/me/wallet/topups', ['amount_kobo' => 10000, 'gateway' => 'opay']), 400, 'validation_failed');

        config(['wegodeliva.payments.topups_enabled' => false]);
        expectApiError(topUp(10000), 422, 'topups_disabled');
        expect(WalletTopup::query()->count())->toBe(0);
    });

    it('fails pending top-ups after the timeout, and still credits money that arrives later', function () {
        topUp(100000);
        $payment = Payment::query()->sole();
        WalletTopup::query()->update(['created_at' => now()->subMinutes(16)]);

        $this->artisan('payments:expire')->expectsOutput('Cancelled 0 unpaid order(s); failed 1 top-up(s).');
        expect(WalletTopup::query()->sole()->status)->toBe('failed');

        $this->gateway->pays($payment);
        $this->withToken($this->token)->postJson("/api/v1/me/payments/{$payment->reference}/verify")->assertJsonPath('data.status', 'succeeded');
        expect(WalletTopup::query()->sole()->status)->toBe('succeeded')
            ->and($this->wallets->wallet($this->customer)->balance_kobo)->toBe(100000);
    });

    it('keeps a pending top-up pending when the gateway confirms it in the last check', function () {
        topUp(100000);
        $this->gateway->pays(Payment::query()->sole());
        WalletTopup::query()->update(['created_at' => now()->subMinutes(16)]);

        $this->artisan('payments:expire')->expectsOutput('Cancelled 0 unpaid order(s); failed 0 top-up(s).');
        expect($this->wallets->wallet($this->customer)->balance_kobo)->toBe(100000);
    });
});

describe('webhooks', function () {
    beforeEach(function () {
        $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
        config(['services.paystack' => ['base_url' => 'https://paystack.test', 'secret_key' => 'sk_test_hook']]);
        $vendor = Checkout::vendor();
        $quote = Checkout::quote($this->token, Checkout::cart($vendor, [[Checkout::product($vendor)]], Checkout::address($this->customer)))->json('data.id');
        $this->order = Order::query()->where('code', Checkout::place($this->token, $quote, 'paystack')->json('data.code'))->sole();
        Http::fake(['paystack.test/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        $this->withToken($this->token)->postJson("/api/v1/me/orders/{$this->order->code}/pay")->assertCreated();
        $this->payment = Payment::query()->sole();
    });

    function paystackHook(array $payload, ?string $secret = 'sk_test_hook'): TestResponse
    {
        $body = json_encode($payload);

        return test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $secret === null ? 'bad' : hash_hmac('sha512', $body, $secret),
        ], $body);
    }

    it('verifies the payment with Paystack when a signed charge webhook arrives, once', function () {
        Http::fake(['paystack.test/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'reference' => $this->payment->reference, 'amount' => 200000, 'currency' => 'NGN', 'id' => 4242, 'channel' => 'bank_transfer',
        ]])]);
        $payload = ['event' => 'charge.success', 'data' => ['id' => 4242, 'reference' => $this->payment->reference, 'amount' => 1]];

        paystackHook($payload)->assertOk()->assertJson(['received' => true]);
        paystackHook($payload)->assertOk();

        $event = WebhookEvent::query()->sole();
        expect($event->processed_at)->not->toBeNull()
            ->and($event->event_key)->toBe('charge.success:4242')
            ->and($this->payment->fresh()->channel)->toBe('bank_transfer')
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Placed);
        // The payload's amount (1) was ignored: only the verified amount counts.
        Http::assertSentCount(1); // one verification for two deliveries
    });

    it('rejects webhooks with a bad signature and ignores ones it cannot read', function () {
        expectApiError(paystackHook(['event' => 'charge.success', 'data' => ['id' => 1]], null), 401, 'invalid_signature');
        paystackHook(['hello' => 'world'])->assertOk();
        paystackHook(['event' => 'transfer.success', 'data' => ['id' => 9]])->assertOk();

        expect(WebhookEvent::query()->pluck('event_type')->all())->toBe(['transfer.success'])
            ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull()
            ->and($this->order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
    });

    it('ignores charge webhooks for unknown references', function () {
        paystackHook(['event' => 'charge.success', 'data' => ['id' => 5, 'reference' => 'NOT-OURS']])->assertOk();

        expect(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
    });

    it('records the error when processing keeps failing, and skips events already processed', function () {
        $event = new WebhookEvent;
        $event->forceFill(['gateway' => 'paystack', 'event_type' => 'charge.success', 'event_key' => 'k', 'payload' => ['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $this->payment->reference]]])->save();

        (new ProcessWebhookEvent($event->id))->failed(new RuntimeException('Paystack timed out'));
        expect($event->fresh()->processing_error)->toBe('Paystack timed out');

        $event->forceFill(['processed_at' => now()])->save();
        Http::fake(fn () => throw new RuntimeException('should not be called'));
        app()->call([new ProcessWebhookEvent($event->id), 'handle']);
        expect($this->order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
    });
});

describe('reconciliation', function () {
    it('reports ok when the gateways hold at least what is owed', function () {
        $paystack = new FakeGateway;
        $paystack->balance = 400000;
        app()->instance(PaystackClient::class, $paystack);
        config(['services.paystack.secret_key' => 'sk', 'services.flutterwave.secret_key' => null]);
        $this->wallets->credit($this->customer, 300000, WalletKind::TopUp, 't');
        $this->wallets->adjust(makeUser(), $this->customer, -5000, 'Debt', 'd'); // debts do not offset what is owed
        $ledger = app(EarningsLedger::class);
        $ledger->recordOrderEarning($ledger->account(EarningsOwner::Vendor, 1), new EarningsShare(100000, 100000, 500, 5000, 375), 'o1');

        $this->artisan('payments:reconcile')->assertSuccessful();

        $report = ReconciliationReport::query()->sole();
        expect($report->status)->toBe('ok')
            ->and($report->owed_kobo)->toBe(300000 + 94625)
            ->and($report->held_kobo)->toBe(400000)
            ->and($report->shortfall_kobo)->toBe(-5375)
            ->and($report->gateways)->toBe(['paystack' => 400000])
            ->and(AdminAuditLog::query()->count())->toBe(0);
    });

    it('flags a shortfall, and an incomplete run when a gateway cannot be read', function () {
        $paystack = new FakeGateway;
        $flutterwave = new FakeGateway;
        app()->instance(PaystackClient::class, $paystack);
        app()->instance(FlutterwaveClient::class, $flutterwave);
        config(['services.paystack.secret_key' => 'sk', 'services.flutterwave.secret_key' => 'fk']);
        $this->wallets->credit($this->customer, 300000, WalletKind::TopUp, 't');
        $paystack->balance = 100000;
        $flutterwave->balance = 150000;

        $this->artisan('payments:reconcile')->assertFailed();
        expect(ReconciliationReport::query()->sole()->only(['status', 'shortfall_kobo']))->toBe(['status' => 'shortfall', 'shortfall_kobo' => 50000])
            ->and(AdminAuditLog::query()->where('action', 'reconciliation.shortfall')->count())->toBe(1);

        // Same day: the report is replaced, not duplicated.
        $flutterwave->down = true;
        $this->artisan('payments:reconcile')->assertFailed();
        expect(ReconciliationReport::query()->sole()->only(['status', 'held_kobo']))->toBe(['status' => 'incomplete', 'held_kobo' => null]);

        config(['services.paystack.secret_key' => null, 'services.flutterwave.secret_key' => null]);
        $this->artisan('payments:reconcile')->assertFailed();
        expect(ReconciliationReport::query()->sole()->gateways)->toBe([]);
    });
});

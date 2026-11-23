<?php

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Services\Payments\Gateways\FlutterwaveClient;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\Gateways\PaystackClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack' => ['base_url' => 'https://paystack.test', 'secret_key' => 'sk_test_paystack'],
        'services.flutterwave' => ['base_url' => 'https://flw.test', 'secret_key' => 'FLWSECK_TEST', 'secret_hash' => 'my-hash'],
    ]);
});

function gatewayPayment(int $amount = 150050): Payment
{
    $payment = new Payment;
    $payment->forceFill(['uuid' => 'p-uuid', 'reference' => 'WGD-REF1', 'amount_kobo' => $amount, 'currency' => 'NGN']);

    return $payment;
}

function webhookRequest(string $body, array $headers): Request
{
    $request = Request::create('/api/v1/webhooks/x', 'POST', [], [], [], [], $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

it('starts a Paystack checkout in kobo with our reference and secret key', function () {
    Http::fake(['paystack.test/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);

    expect((new PaystackClient)->initialize(gatewayPayment(), 'ada@example.com', 'https://wegodeliva.com/payment/return'))->toBe('https://checkout.paystack.com/abc');

    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_paystack')
        && $r['amount'] === 150050 && $r['reference'] === 'WGD-REF1' && $r['currency'] === 'NGN' && $r['email'] === 'ada@example.com');
});

it('reads Paystack verification results', function (array $response, int $status, array $expected) {
    Http::fake(['paystack.test/transaction/verify/WGD-REF1' => Http::response($response, $status)]);

    $result = (new PaystackClient)->verify('WGD-REF1');

    expect([$result->successful, $result->status, $result->final, $result->amountKobo, $result->transactionId])->toBe($expected);
})->with([
    'success' => [['status' => true, 'data' => ['status' => 'success', 'reference' => 'WGD-REF1', 'amount' => 150050, 'currency' => 'NGN', 'id' => 991, 'channel' => 'card']], 200, [true, 'success', false, 150050, '991']],
    'abandoned' => [['status' => true, 'data' => ['status' => 'abandoned', 'reference' => 'WGD-REF1']], 200, [false, 'abandoned', true, null, null]],
    'still pending' => [['status' => true, 'data' => ['status' => 'ongoing']], 200, [false, 'ongoing', false, null, null]],
    'unknown reference' => [['status' => false, 'message' => 'Transaction reference not found'], 400, [false, 'not_found', false, null, null]],
]);

it('treats Paystack errors and outages as unavailable', function (Closure $fake) {
    $fake();
    (new PaystackClient)->verify('WGD-REF1');
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response(['status' => false], 500)])],
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('timeout'))],
    'malformed body' => [fn () => Http::fake(['*' => Http::response(['status' => true, 'data' => 'x'])])],
])->throws(GatewayUnavailable::class);

it('refuses a Paystack checkout without a URL', function () {
    Http::fake(['*' => Http::response(['status' => true, 'data' => ['access_code' => 'x']])]);
    (new PaystackClient)->initialize(gatewayPayment(), 'a@b.c', 'https://x');
})->throws(GatewayUnavailable::class);

it('checks the Paystack signature on the raw body', function () {
    $body = '{"event":"charge.success","data":{"id":5,"reference":"WGD-REF1"}}';
    $client = new PaystackClient;

    expect($client->hasValidSignature(webhookRequest($body, ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_paystack')])))->toBeTrue()
        ->and($client->hasValidSignature(webhookRequest($body.' ', ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_paystack')])))->toBeFalse()
        ->and($client->hasValidSignature(webhookRequest($body, [])))->toBeFalse();

    config(['services.paystack.secret_key' => '']);
    expect($client->hasValidSignature(webhookRequest($body, ['x-paystack-signature' => hash_hmac('sha512', $body, '')])))->toBeFalse();
});

it('parses Paystack webhooks and balances', function () {
    $client = new PaystackClient;
    $notice = $client->parseWebhook(['event' => 'charge.success', 'data' => ['id' => 5, 'reference' => 'WGD-REF1']]);
    expect([$notice->eventType, $notice->eventKey, $notice->reference, $notice->isCharge])->toBe(['charge.success', 'charge.success:5', 'WGD-REF1', true])
        ->and($client->parseWebhook(['event' => 'transfer.success', 'data' => ['id' => 6]])->isCharge)->toBeFalse()
        ->and($client->parseWebhook(['data' => []]))->toBeNull();

    Http::fake(['paystack.test/balance' => Http::sequence()
        ->push(['status' => true, 'data' => [['currency' => 'USD', 'balance' => 5], ['currency' => 'NGN', 'balance' => 123456]]])
        ->push(['status' => true, 'data' => [['currency' => 'USD', 'balance' => 5]]])]);
    expect($client->balanceKobo())->toBe(123456)->and($client->balanceKobo())->toBe(0);
});

it('converts between kobo and Flutterwave naira exactly', function () {
    expect(FlutterwaveClient::naira(150050))->toBe('1500.50')
        ->and(FlutterwaveClient::naira(7))->toBe('0.07')
        ->and(FlutterwaveClient::kobo('1500.50'))->toBe(150050)
        ->and(FlutterwaveClient::kobo(1500.5))->toBe(150050)
        ->and(FlutterwaveClient::kobo(0.07))->toBe(7)
        ->and(FlutterwaveClient::kobo(2000))->toBe(200000)
        ->and(FlutterwaveClient::kobo('19.999'))->toBe(1999);
});

it('starts a Flutterwave checkout in naira', function () {
    Http::fake(['flw.test/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']])]);

    expect((new FlutterwaveClient)->initialize(gatewayPayment(), 'ada@example.com', 'https://x'))->toBe('https://checkout.flutterwave.com/x');
    Http::assertSent(fn (HttpRequest $r) => $r['amount'] === '1500.50' && $r['tx_ref'] === 'WGD-REF1' && $r['customer']['email'] === 'ada@example.com' && $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST'));
});

it('refuses a Flutterwave checkout without a link', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['id' => 1]])]);
    (new FlutterwaveClient)->initialize(gatewayPayment(), 'a@b.c', 'https://x');
})->throws(GatewayUnavailable::class);

it('reads Flutterwave verification results', function (array $response, int $status, array $expected) {
    Http::fake(['flw.test/v3/transactions/verify_by_reference*' => Http::response($response, $status)]);

    $result = (new FlutterwaveClient)->verify('WGD-REF1');

    expect([$result->successful, $result->status, $result->final, $result->amountKobo, $result->reference])->toBe($expected);
})->with([
    'successful' => [['status' => 'success', 'data' => ['status' => 'successful', 'tx_ref' => 'WGD-REF1', 'amount' => 1500.5, 'currency' => 'NGN', 'id' => 77, 'payment_type' => 'card']], 200, [true, 'successful', false, 150050, 'WGD-REF1']],
    'failed' => [['status' => 'success', 'data' => ['status' => 'failed']], 200, [false, 'failed', true, null, null]],
    'not found' => [['status' => 'error', 'message' => 'No transaction was found'], 404, [false, 'not_found', false, null, null]],
]);

it('treats Flutterwave errors and outages as unavailable', function (Closure $fake) {
    $fake();
    (new FlutterwaveClient)->balanceKobo();
})->with([
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('down'))],
    'server error' => [fn () => Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Oops'], 500)])],
])->throws(GatewayUnavailable::class);

it('checks the Flutterwave secret hash and parses its webhooks and balance', function () {
    $client = new FlutterwaveClient;
    expect($client->hasValidSignature(webhookRequest('{}', ['verif-hash' => 'my-hash'])))->toBeTrue()
        ->and($client->hasValidSignature(webhookRequest('{}', ['verif-hash' => 'wrong'])))->toBeFalse()
        ->and($client->hasValidSignature(webhookRequest('{}', [])))->toBeFalse();

    $notice = $client->parseWebhook(['event' => 'charge.completed', 'data' => ['id' => 9, 'tx_ref' => 'WGD-REF1']]);
    expect([$notice->eventKey, $notice->reference, $notice->isCharge])->toBe(['charge.completed:9', 'WGD-REF1', true])
        ->and($client->parseWebhook(['event' => 'charge.completed']))->toBeNull()
        ->and($client->parseWebhook(['event' => 'transfer.completed', 'data' => ['id' => 1]])->reference)->toBeNull();

    Http::fake(['flw.test/v3/balances/NGN' => Http::response(['status' => 'success', 'data' => ['currency' => 'NGN', 'available_balance' => 2500.75]])]);
    expect($client->balanceKobo())->toBe(250075);
});

function transferFixtures(): array
{
    $payout = new Payout;
    $payout->forceFill(['transfer_reference' => 'WGDP-REF', 'amount_kobo' => 250075]);
    $account = new PayoutAccount;
    $account->forceFill(['account_name' => 'ADA OBI', 'account_number' => '0123456789', 'bank_code' => '058', 'recipient_code' => null]);

    return [$payout, $account];
}

it('uses the Paystack transfers API for banks, account names and payouts', function () {
    Http::fake([
        'paystack.test/bank?*' => Http::response(['status' => true, 'data' => [['code' => '058', 'name' => 'GTBank'], 'junk']]),
        'paystack.test/bank/resolve*' => Http::sequence()
            ->push(['status' => true, 'data' => ['account_name' => 'ADA OBI']])
            ->push(['status' => false, 'message' => 'Could not resolve account name'], 422),
        'paystack.test/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']]),
        'paystack.test/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_1', 'status' => 'pending']]),
        'paystack.test/transfer/verify/*' => Http::sequence()
            ->push(['status' => true, 'data' => ['status' => 'success']])
            ->push(['status' => true, 'data' => ['status' => 'reversed']])
            ->push(['status' => true, 'data' => ['status' => 'otp']]),
    ]);
    $client = new PaystackClient;
    [$payout, $account] = transferFixtures();
    $account->forceFill(['owner_type' => 'vendor', 'owner_id' => 1, 'bank_name' => 'GTBank', 'status' => 'verified'])->save();

    expect($client->banks())->toBe([['code' => '058', 'name' => 'GTBank']])
        ->and($client->resolveAccount('058', '0123456789'))->toBe('ADA OBI')
        ->and($client->resolveAccount('058', '9999999999'))->toBeNull();

    expect($client->sendTransfer($payout, $account))->toBe('TRF_1')
        ->and($account->fresh()->recipient_code)->toBe('RCP_1')
        ->and($client->sendTransfer($payout, $account))->toBe('TRF_1'); // recipient reused
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transfer') && $r['amount'] === 250075 && $r['recipient'] === 'RCP_1' && $r['reference'] === 'WGDP-REF');
    Http::assertSentCount(6);

    expect([$client->transferStatus('WGDP-REF'), $client->transferStatus('WGDP-REF'), $client->transferStatus('WGDP-REF')])->toBe(['success', 'failed', 'pending']);
});

it('refuses a Paystack transfer without an id', function () {
    Http::fake(['paystack.test/transfer' => Http::response(['status' => true, 'data' => ['status' => 'pending']])]);
    [$payout, $account] = transferFixtures();
    $account->recipient_code = 'RCP_1';

    (new PaystackClient)->sendTransfer($payout, $account);
})->throws(GatewayUnavailable::class);

it('uses the Flutterwave transfers API in naira', function () {
    Http::fake([
        'flw.test/v3/banks/NG' => Http::response(['status' => 'success', 'data' => [['code' => '044', 'name' => 'Access Bank']]]),
        'flw.test/v3/accounts/resolve' => Http::sequence()
            ->push(['status' => 'success', 'data' => ['account_name' => 'ADA OBI']])
            ->push(['status' => 'error', 'message' => 'Invalid account'], 400),
        'flw.test/v3/transfers?*' => Http::sequence()
            ->push(['status' => 'success', 'data' => [['status' => 'SUCCESSFUL']]])
            ->push(['status' => 'success', 'data' => [['status' => 'FAILED']]])
            ->push(['status' => 'success', 'data' => []]),
        'flw.test/v3/transfers' => Http::response(['status' => 'success', 'data' => ['id' => 777, 'status' => 'NEW']]),
    ]);
    $client = new FlutterwaveClient;
    [$payout, $account] = transferFixtures();

    expect($client->banks())->toBe([['code' => '044', 'name' => 'Access Bank']])
        ->and($client->resolveAccount('044', '0123456789'))->toBe('ADA OBI')
        ->and($client->resolveAccount('044', '0000000000'))->toBeNull()
        ->and($client->sendTransfer($payout, $account))->toBe('777')
        ->and([$client->transferStatus('WGDP-REF'), $client->transferStatus('WGDP-REF'), $client->transferStatus('WGDP-REF')])->toBe(['success', 'failed', 'pending']);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v3/transfers') && $r['amount'] === '2500.75' && $r['account_bank'] === '058');
});

it('refuses a Flutterwave transfer without an id', function () {
    Http::fake(['flw.test/v3/transfers' => Http::response(['status' => 'success', 'data' => ['status' => 'NEW']])]);
    [$payout, $account] = transferFixtures();

    (new FlutterwaveClient)->sendTransfer($payout, $account);
})->throws(GatewayUnavailable::class);

it('recognises transfer webhooks from both gateways', function () {
    $paystack = (new PaystackClient)->parseWebhook(['event' => 'transfer.reversed', 'data' => ['id' => 3, 'reference' => 'WGDP-REF']]);
    $flutterwave = (new FlutterwaveClient)->parseWebhook(['event' => 'transfer.completed', 'data' => ['id' => 4, 'reference' => 'WGDP-REF', 'tx_ref' => 'ignored']]);

    expect([$paystack->isTransfer, $paystack->isCharge, $paystack->reference])->toBe([true, false, 'WGDP-REF'])
        ->and([$flutterwave->isTransfer, $flutterwave->reference])->toBe([true, 'WGDP-REF']);
});

<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Ledger\WalletLedger;
use App\Services\Payments\Gateways\PaystackClient;
use App\Services\Payments\Gateways\VerifiedTransaction;
use App\Services\Payments\PaymentProcessor;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\Checkout;
use Tests\Support\FakeGateway;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    config(['wegodeliva.payments.paystack' => true]);
    $this->gateway = new FakeGateway;
    app()->instance(PaystackClient::class, $this->gateway);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor, ['stock_qty' => 5]);
    $this->address = Checkout::address($this->customer);
});

/** Places a Paystack order and starts its checkout; returns [order, payment]. */
function gatewayOrder(array $extra = [], string $key = 'k1'): array
{
    $t = test();
    $quote = Checkout::quote($t->token, Checkout::cart($t->vendor, [[$t->product]], $t->address, $extra))->assertCreated()->json('data.id');
    $code = Checkout::place($t->token, $quote, 'paystack', $key)->assertCreated()->json('data.code');
    $reference = $t->withToken($t->token)->postJson("/api/v1/me/orders/{$code}/pay")->assertCreated()->json('data.reference');

    return [Order::query()->where('code', $code)->sole(), Payment::query()->where('reference', $reference)->sole()];
}

function verifyPayment(Payment $payment): TestResponse
{
    return test()->withToken(test()->token)->postJson("/api/v1/me/payments/{$payment->reference}/verify");
}

it('starts a checkout and moves the order on once the server has verified the payment', function () {
    $quote = Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address))->json('data.id');
    $code = Checkout::place($this->token, $quote, 'paystack')->json('data.code');

    $response = $this->withToken($this->token)->postJson("/api/v1/me/orders/{$code}/pay")
        ->assertCreated()
        ->assertJsonPath('data.gateway', 'paystack')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount.amount_kobo', 200000);
    $payment = Payment::query()->sole();
    expect($response->json('data.checkout_url'))->toBe("https://checkout.test/{$payment->reference}")
        ->and($payment->reference)->toStartWith('WGD-')
        ->and(Order::query()->sole()->payment_status)->toBe(PaymentStatus::Pending);

    // Still pending at the gateway: nothing changes.
    verifyPayment($payment)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.order.status', 'awaiting_payment');

    $this->gateway->pays($payment);
    verifyPayment($payment)->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.checkout_url', null)
        ->assertJsonPath('data.order.status', 'placed')
        ->assertJsonPath('data.order.payment_status', 'paid');

    $order = Order::query()->sole();
    $payment->refresh();
    expect($order->placed_at)->not->toBeNull()
        ->and($order->history()->get()->last()->reason)->toBe('Payment verified')
        ->and([$payment->gateway_transaction_id, $payment->channel])->toBe(["txn-{$payment->id}", 'card'])
        ->and($payment->raw_verification)->toMatchArray(['status' => 'success', 'amount_kobo' => 200000]);

    // Verifying again (or a webhook arriving later) changes nothing.
    verifyPayment($payment)->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect($order->history()->count())->toBe(2);
});

it('rejects payments whose amount, currency, reference or transaction do not match', function (Closure $tamper, string $reason) {
    [$order, $payment] = gatewayOrder();
    $tamper($this->gateway, $payment);

    verifyPayment($payment)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', $reason);

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->gateway_transaction_id)->toBeNull()
        ->and(AdminAuditLog::query()->where('action', 'payment.rejected')->sole()->after['reason'])->toBe($reason);
})->with([
    'tampered amount' => [fn ($g, $p) => $g->pays($p, amount: $p->amount_kobo - 1), 'amount_mismatch'],
    'wrong currency' => [fn ($g, $p) => $g->pays($p, currency: 'USD'), 'currency_mismatch'],
    'someone else\'s reference' => [fn ($g, $p) => $g->pays($p, reference: 'WGD-OTHER'), 'reference_mismatch'],
    'no transaction id' => [function ($g, $p) {
        $g->results[$p->reference] = new VerifiedTransaction(true, 'success', $p->reference, $p->amount_kobo, 'NGN', null, 'card');
    }, 'transaction_reused'],
]);

it('refuses a gateway transaction that already paid for something else (replay)', function () {
    [$first, $firstPayment] = gatewayOrder();
    $this->gateway->pays($firstPayment, txn: 'txn-shared');
    verifyPayment($firstPayment)->assertJsonPath('data.status', 'succeeded');

    [$second, $secondPayment] = gatewayOrder(key: 'k2');
    $this->gateway->pays($secondPayment, txn: 'txn-shared');
    verifyPayment($secondPayment)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'transaction_reused');

    expect($second->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('marks a payment failed when the gateway says it failed, and allows a retry', function () {
    [$order, $payment] = gatewayOrder();
    $this->gateway->fails($payment, 'abandoned');

    verifyPayment($payment)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'gateway_abandoned');

    $retry = $this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/pay")->assertCreated()->json('data.reference');
    expect($retry)->not->toBe($payment->reference)->and(Payment::query()->where('order_id', $order->id)->count())->toBe(2);
});

it('sends paid scheduled orders to scheduled, and auto-accept stores accept them', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Africa/Lagos'));
    [$scheduled, $p1] = gatewayOrder(['scheduled_for' => '2026-12-01T14:00:00+01:00']);
    $this->gateway->pays($p1);
    verifyPayment($p1)->assertJsonPath('data.order.status', 'scheduled');

    $this->vendor->update(['auto_accept_orders' => true]);
    [$now, $p2] = gatewayOrder(key: 'k2');
    $this->gateway->pays($p2);
    verifyPayment($p2)->assertJsonPath('data.order.status', 'accepted');
    Carbon::setTestNow();
});

it('refuses to start a checkout that cannot be paid', function () {
    [$order, $payment] = gatewayOrder();
    $this->gateway->pays($payment);
    verifyPayment($payment);

    $error = expectApiError($this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/pay"), 409, 'order_not_payable');
    expect($error['meta']['status'])->toBe('placed');

    $pod = Checkout::place($this->token, Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address))->json('data.id'), key: 'k9')->json('data.code');
    expectApiError($this->withToken($this->token)->postJson("/api/v1/me/orders/{$pod}/pay"), 409, 'order_not_payable');

    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken(Checkout::token(makeUser()))->postJson("/api/v1/me/orders/{$order->code}/pay"), 404, 'not_found');
    expectApiError($this->withToken(Checkout::token(makeUser()))->postJson("/api/v1/me/payments/{$payment->reference}/verify"), 404, 'not_found');
});

it('answers 503 when the gateway is down, without losing the order', function () {
    [$order, $payment] = gatewayOrder();
    $this->gateway->down = true;

    expectApiError(verifyPayment($payment), 503, 'gateway_unavailable');
    expectApiError($this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/pay"), 503, 'gateway_unavailable');

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Payment::query()->where('order_id', $order->id)->latest('id')->first()->failure_reason)->toBe('gateway_unavailable');
});

it('refuses a gateway that has been switched off', function () {
    [$order] = gatewayOrder();
    config(['wegodeliva.payments.paystack' => false]);

    expectApiError($this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/pay"), 422, 'payment_method_unavailable');
});

it('cancels unpaid orders after the timeout, after one last check with the gateway', function () {
    [$unpaid, $p1] = gatewayOrder();
    [$paidLate, $p2] = gatewayOrder(key: 'k2');
    [$recent] = gatewayOrder(key: 'k3');
    Order::query()->whereKey([$unpaid->id, $paidLate->id])->update(['created_at' => now()->subMinutes(16)]);
    $this->gateway->pays($p2);

    $this->artisan('payments:expire')->expectsOutput('Cancelled 1 unpaid order(s); failed 0 top-up(s).')->assertSuccessful();

    $unpaid->refresh();
    expect($unpaid->status)->toBe(OrderStatus::Cancelled)
        ->and($unpaid->payment_status)->toBe(PaymentStatus::Failed)
        ->and($unpaid->cancel_reason)->toBe('Payment not completed in time')
        ->and($unpaid->fault->value)->toBe('none')
        ->and($p1->fresh()->status)->toBe(PaymentAttemptStatus::Abandoned)
        ->and($this->product->fresh()->stock_qty)->toBe(3) // stock back for the cancelled order
        ->and($paidLate->fresh()->status)->toBe(OrderStatus::Placed)
        ->and($recent->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('still cancels when the gateway cannot be reached for the last check', function () {
    [$order, $payment] = gatewayOrder();
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subMinutes(16)]);
    $this->gateway->down = true;

    app(PaymentProcessor::class)->expireStale();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and($payment->fresh()->status)->toBe(PaymentAttemptStatus::Abandoned);
});

it('returns money that arrives after the order was cancelled to the wallet', function () {
    [$order, $payment] = gatewayOrder();
    $this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/cancel")->assertOk();

    $this->gateway->pays($payment);
    verifyPayment($payment)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.order.status', 'cancelled')->assertJsonPath('data.order.payment_status', 'refunded');

    $refund = app(WalletLedger::class)->wallet($this->customer)->transactions()->sole();
    expect([$refund->kind->value, $refund->amount_kobo, $refund->gateway])->toBe(['refund', 200000, 'paystack'])
        ->and(AdminAuditLog::query()->where('action', 'payment.refunded_late')->count())->toBe(1);
});

it('refunds a second successful payment for an order that was already paid', function () {
    [$order, $first] = gatewayOrder();
    $second = Payment::query()->where('reference', $this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/pay")->json('data.reference'))->sole();
    $this->gateway->pays($first);
    $this->gateway->pays($second);

    verifyPayment($first)->assertJsonPath('data.order.payment_status', 'paid');
    verifyPayment($second)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.order.payment_status', 'paid');

    expect(app(WalletLedger::class)->wallet($this->customer)->balance_kobo)->toBe(200000);
});

it('applies a result only once, even from a stale copy of the payment', function () {
    [$order, $payment] = gatewayOrder();
    $stale = Payment::query()->whereKey($payment->id)->sole();
    $this->gateway->pays($payment);
    $processor = app(PaymentProcessor::class);
    $processor->verify($payment);

    $again = $processor->apply($stale, $this->gateway->results[$payment->reference]);

    expect($again->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($order->history()->count())->toBe(2)
        ->and(app(WalletLedger::class)->wallet($this->customer)->balance_kobo)->toBe(0);
});

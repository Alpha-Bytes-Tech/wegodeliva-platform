<?php

use App\Enums\ActorRole;
use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Events\OrderAlert;
use App\Events\PaymentFailed;
use App\Events\WalletCredited;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\FcmChannel;
use App\Services\Documents;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\WalletLedger;
use App\Services\Notifications\FcmClient;
use App\Services\Orders\OrderFulfilment;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\Refunds;
use App\Services\Payments\Gateways\PaystackClient;
use App\Services\Payments\Payouts;
use App\Services\Pricing\EarningsShare;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Checkout;
use Tests\Support\FakeGateway;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->vendor = Checkout::vendor(['name' => 'Mama Put']);
    $this->owner = makeUser(RoleName::VendorOwner);
    $this->staff = makeUser(RoleName::VendorStaff);
    $this->vendor->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->vendor->members()->attach($this->staff->id, ['role' => 'staff']);
    $this->product = Checkout::product($this->vendor);
    Checkout::address($this->customer);
    $this->admin = makeUser(RoleName::Admin);
});

function notifiedOrder(string $method = 'pay_on_delivery', string $key = 'k1'): Order
{
    $t = test();
    app('auth')->forgetGuards();
    $token = Checkout::token($t->customer);
    $quote = Checkout::quote($token, Checkout::cart($t->vendor, [[$t->product, 2]], $t->customer->addresses()->first()))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    return Order::query()->where('code', Checkout::place($token, $quote, $method, $key)->assertCreated()->json('data.code'))->sole();
}

/** @return list<string> notification keys sent to $user, in order */
function keysFor(User $user): array
{
    $keys = [];
    Notification::assertSentTo($user, AppNotification::class, function (AppNotification $n) use (&$keys) {
        $keys[] = $n->key;

        return true;
    });

    return $keys;
}

it('stores notifications in each app\'s inbox once the order is saved, and marks them read', function () {
    $order = notifiedOrder();

    app('auth')->forgetGuards();
    $inbox = $this->withToken(tokenFor($this->customer, ClientApp::Customer))->getJson('/api/v1/me/notifications')->assertOk()
        ->assertJsonPath('data.0.key', 'customer.order_placed')
        ->assertJsonPath('data.0.title', 'Order received')
        ->assertJsonPath('data.0.body', "Mama Put has your order {$order->code}.")
        ->assertJsonPath('data.0.data.order', $order->code)
        ->assertJsonPath('meta.unread', 1);
    expect($inbox->json('data'))->toHaveCount(1);

    app('auth')->forgetGuards();
    $staffInbox = fn () => $this->withToken(tokenFor($this->staff, ClientApp::Vendor))->getJson('/api/v1/vendor/notifications');
    $staffInbox()->assertOk()->assertJsonPath('data.0.title', "New order {$order->code}")->assertJsonPath('data.0.body', '2 item(s), ₦3,500.00. Open to accept.');

    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->staff, ClientApp::Vendor))->postJson('/api/v1/vendor/notifications/read')->assertOk()->assertJsonPath('data.marked', 1);
    app('auth')->forgetGuards();
    $staffInbox()->assertJsonPath('meta.unread', 0)->assertJsonPath('data.0.read', true);

    app('auth')->forgetGuards();
    $id = $this->withToken(tokenFor($this->customer, ClientApp::Customer))->getJson('/api/v1/me/notifications?unread=1')->json('data.0.id');
    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->customer, ClientApp::Customer))->postJson('/api/v1/me/notifications/read', ['ids' => [$id]])->assertJsonPath('data.marked', 1);

    // A user's rider inbox is separate from their customer inbox.
    app('auth')->forgetGuards();
    $this->withToken(tokenFor(makeUser(RoleName::Rider), ClientApp::Rider))->getJson('/api/v1/rider/notifications')->assertOk()->assertJsonPath('data', []);
});

it('tells each party about an order\'s life, and emails the receipt', function () {
    Notification::fake();
    $order = notifiedOrder();
    $rider = makeUser(RoleName::Rider);
    $rider->riderProfile()->forceCreate(['rider_type' => 'independent', 'approved_at' => now()]);
    $fulfilment = app(OrderFulfilment::class);

    $fulfilment->accept($order, $this->staff, 25);
    $fulfilment->assign($order, $rider, $this->admin);
    $fulfilment->ready($order, $this->staff);
    $fulfilment->pickedUp($order, $rider);
    $fulfilment->riderDeliver($order, $rider, $order->handover_code, 'cash');

    expect(keysFor($this->customer))->toBe(['customer.order_placed', 'customer.order_accepted', 'customer.rider_assigned', 'customer.order_picked_up', 'customer.order_delivered'])
        ->and(keysFor($this->staff))->toBe(['vendor.new_order', 'vendor.rider_assigned', 'vendor.order_picked_up', 'vendor.order_delivered'])
        ->and(keysFor($rider))->toBe(['rider.job_assigned', 'rider.order_ready', 'rider.job_delivered']);

    Notification::assertSentTo($this->staff, AppNotification::class, fn (AppNotification $n) => $n->key !== 'vendor.new_order'
        || ($n->urgent && $n->channel === 'new_orders' && $n->app === ClientApp::Vendor && $n->data['vendor'] === $this->vendor->uuid));
    Notification::assertSentTo($this->customer, AppNotification::class, function (AppNotification $n) use ($order) {
        if ($n->key !== 'customer.order_delivered') {
            return true;
        }
        $mail = $n->toMail($this->customer);
        expect($n->via($this->customer))->toBe(['database', FcmChannel::class, 'mail'])
            ->and($mail->subject)->toBe('Delivered')
            ->and($mail->introLines)->toContain("Receipt for order {$order->code} from Mama Put:", '2 × '.$this->product->name.': ₦3,000.00', 'Total: ₦3,500.00 (pay on delivery)');

        return true;
    });
    Notification::assertSentTo($rider, AppNotification::class, fn (AppNotification $n) => $n->key !== 'rider.job_assigned' || $n->urgent);
});

it('handles pickups, cancellations and failures for the right people', function () {
    Notification::fake();
    $pickupQuote = Checkout::quote(Checkout::token($this->customer), Checkout::cart($this->vendor, [[$this->product]], null))->json('data.id');
    app('auth')->forgetGuards();
    $pickup = Order::query()->where('code', Checkout::place(Checkout::token($this->customer), $pickupQuote, key: 'p')->json('data.code'))->sole();
    app(OrderStateMachine::class)->transition($pickup, OrderStatus::Accepted, ActorRole::Vendor);
    app(OrderStateMachine::class)->transition($pickup, OrderStatus::Ready, ActorRole::Vendor);

    $byCustomer = notifiedOrder(key: 'a');
    app(OrderLifecycle::class)->cancel($byCustomer, ActorRole::Customer, $this->customer, 'Changed my mind', Fault::Customer);
    $byVendor = notifiedOrder(key: 'b');
    app(OrderFulfilment::class)->vendorCancel($byVendor, $this->staff, 'Out of rice');
    $failed = notifiedOrder(key: 'c');
    app(OrderStateMachine::class)->transition($failed, OrderStatus::Failed, ActorRole::Admin, $this->admin, 'Lost');

    $customerKeys = keysFor($this->customer);
    expect($customerKeys)->toContain('customer.order_ready_pickup', 'customer.order_failed')
        ->and(array_count_values($customerKeys)['customer.order_cancelled'] ?? 0)->toBe(1); // not for their own cancellation
    $vendorKeys = keysFor($this->staff);
    expect(array_count_values($vendorKeys)['vendor.order_cancelled'] ?? 0)->toBe(1) // not for the store's own rejection
        ->and($vendorKeys)->toContain('vendor.order_failed');
    Notification::assertSentTo($this->customer, AppNotification::class, fn (AppNotification $n) => $n->key !== 'customer.order_cancelled' || $n->body() === "Order {$byVendor->code} was cancelled: Out of rice");
});

it('confirms scheduled and paid orders, and tells customers about payments, refunds and wallet credits', function () {
    Notification::fake();
    config(['wegodeliva.payments.paystack' => true]);
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Africa/Lagos'));
    $token = Checkout::token($this->customer);
    $quote = Checkout::quote($token, Checkout::cart($this->vendor, [[$this->product]], $this->customer->addresses()->first(), ['scheduled_for' => '2026-12-01T14:00:00+01:00']))->json('data.id');
    app('auth')->forgetGuards();
    Checkout::place($token, $quote, key: 's')->assertCreated();
    Carbon::setTestNow();

    $gateway = notifiedOrder('paystack', 'g');
    $payment = new Payment;
    $payment->forceFill(['user_id' => $this->customer->id, 'order_id' => $gateway->id, 'gateway' => 'paystack', 'reference' => 'WGD-X', 'amount_kobo' => $gateway->total_kobo, 'status' => 'failed'])->save();
    event(new PaymentFailed($payment->id));

    $wallets = app(WalletLedger::class);
    $topup = $wallets->credit($this->customer, 500000, WalletKind::TopUp, 't1');
    event(new WalletCredited($topup->id));
    $refund = $wallets->credit($this->customer, 100, WalletKind::Refund, 'x1');
    event(new WalletCredited($refund->id)); // refunds have their own message

    $paid = notifiedOrder('wallet', 'w');
    app(Refunds::class)->refund($paid, 1000, 'Missing drink', Fault::Vendor, $this->admin, 'r1');

    expect(keysFor($this->customer))->toBe(['customer.order_scheduled', 'customer.payment_failed', 'customer.topup_credited', 'customer.order_placed', 'customer.refund_issued']);
    Notification::assertSentTo($this->customer, AppNotification::class, fn (AppNotification $n) => $n->key !== 'customer.order_scheduled' || str_contains($n->body(), 'Tue 1 Dec, 2:00 pm'));
    Notification::assertSentTo($this->customer, AppNotification::class, fn (AppNotification $n) => $n->key !== 'customer.refund_issued' || ($n->mail && $n->body() === "₦10.00 for order {$paid->code} is now in your wallet."));
});

it('reminds slow stores, warns about missing riders, and tells owners about payouts and documents', function () {
    Notification::fake();
    $order = notifiedOrder();
    event(new OrderAlert($order->id, 'vendor_slow'));
    event(new OrderAlert($order->id, 'no_rider'));
    event(new OrderAlert($order->id, 'something_else'));

    $ledger = app(EarningsLedger::class);
    $account = $ledger->account(EarningsOwner::Vendor, $this->vendor->id);
    $ledger->recordOrderEarning($account, new EarningsShare(500000, 500000, 500, 0, 0), 'e1');
    $bank = new PayoutAccount;
    $bank->forceFill(['owner_type' => 'vendor', 'owner_id' => $this->vendor->id, 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'X', 'status' => 'verified'])->save();
    $fake = new FakeGateway;
    app()->instance(PaystackClient::class, $fake);
    $payouts = app(Payouts::class);
    $payout = $payouts->request($account, $bank, 100000, $this->owner, 'p1');
    $payouts->approve($payout, $this->admin);
    $fake->transfers[$payout->transfer_reference] = 'success';
    $payouts->sync($payout);
    $rejected = $payouts->request($account, $bank, 100000, $this->owner, 'p2');
    $payouts->reject($rejected, $this->admin, 'Bank details under review');

    app(Documents::class)->request(EarningsOwner::Vendor, $this->vendor->id, 'cac_certificate', $this->admin, 'Both pages');

    expect(keysFor($this->staff))->toBe(['vendor.new_order', 'vendor.accept_reminder', 'vendor.no_rider'])
        ->and(keysFor($this->owner))->toBe(['vendor.new_order', 'vendor.accept_reminder', 'vendor.no_rider', 'vendor.payout_sent', 'vendor.payout_paid', 'vendor.payout_rejected', 'vendor.document_requested'])
        ->and(keysFor($this->customer))->toContain('customer.still_finding_rider');
    Notification::assertSentTo($this->owner, AppNotification::class, fn (AppNotification $n) => $n->key !== 'vendor.payout_rejected' || ($n->mail && $n->body() === 'Your payout of ₦1,000.00 was declined: Bank details under review'));
    Notification::assertSentTo($this->owner, AppNotification::class, fn (AppNotification $n) => $n->key !== 'vendor.document_requested' || $n->body() === 'Please upload your cac certificate. Both pages');
});

it('tells riders about their payouts and documents', function () {
    Notification::fake();
    $rider = makeUser(RoleName::Rider);
    $rider->riderProfile()->forceCreate(['rider_type' => 'independent', 'approved_at' => now()]);
    app(Documents::class)->request(EarningsOwner::Rider, $rider->id, 'drivers_licence', $this->admin, null);

    $ledger = app(EarningsLedger::class);
    $account = $ledger->account(EarningsOwner::Rider, $rider->id);
    $ledger->recordOrderEarning($account, new EarningsShare(500000, 500000, 300, 0, 0), 'e1');
    $bank = new PayoutAccount;
    $bank->forceFill(['owner_type' => 'rider', 'owner_id' => $rider->id, 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'X', 'status' => 'verified'])->save();
    $fake = new FakeGateway;
    app()->instance(PaystackClient::class, $fake);
    $payouts = app(Payouts::class);
    $payout = $payouts->request($account, $bank, 100000, $rider, 'p1');
    $payouts->approve($payout, $this->admin);
    $fake->transfers[$payout->transfer_reference] = 'failed';
    $payouts->sync($payout);

    expect(keysFor($rider))->toBe(['rider.document_requested', 'rider.payout_sent', 'rider.payout_failed']);
});

it('pushes to the user\'s devices for that app only, and drops tokens FCM rejects', function () {
    $fcm = new class extends FcmClient
    {
        public bool $on = true;

        /** @var list<array{0: string, 1: string, 2: bool, 3: string|null, 4: array<string, string>}> */
        public array $sent = [];

        public function enabled(): bool
        {
            return $this->on;
        }

        public function send(string $token, string $title, string $body, array $data, bool $urgent = false, ?string $channel = null): string
        {
            $this->sent[] = [$token, $title, $urgent, $channel, $data];

            return $token === 'dead' ? self::INVALID_TOKEN : self::SENT;
        }
    };
    app()->instance(FcmClient::class, $fcm);
    foreach ([['phone', 'vendor'], ['dead', 'vendor'], ['other-app', 'customer']] as [$token, $app]) {
        $device = new Device;
        $device->forceFill(['user_id' => $this->staff->id, 'app' => $app, 'platform' => 'android', 'fcm_token' => $token])->save();
    }

    $order = notifiedOrder();

    expect(array_column($fcm->sent, 0))->toBe(['phone', 'dead'])
        ->and($fcm->sent[0][2])->toBeTrue()
        ->and($fcm->sent[0][3])->toBe('new_orders')
        ->and($fcm->sent[0][4])->toBe(['key' => 'vendor.new_order', 'order' => $order->code, 'vendor' => $this->vendor->uuid])
        ->and(Device::query()->pluck('fcm_token')->sort()->values()->all())->toBe(['other-app', 'phone']);

    $fcm->on = false;
    $fcm->sent = [];
    notifiedOrder(key: 'k2');
    expect($fcm->sent)->toBe([]);
});

it('shows riders their own inbox and marks it read', function () {
    $rider = makeUser(RoleName::Rider);
    $rider->riderProfile()->forceCreate(['rider_type' => 'independent', 'approved_at' => now()]);
    app(Documents::class)->request(EarningsOwner::Rider, $rider->id, 'drivers_licence', $this->admin, null);
    $token = tokenFor($rider, ClientApp::Rider);

    $this->withToken($token)->getJson('/api/v1/rider/notifications')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->postJson('/api/v1/rider/notifications/read')->assertOk()->assertJsonPath('data.marked', 1);
    $this->withToken($token)->postJson('/api/v1/rider/notifications/read')->assertOk()->assertJsonPath('data.marked', 0);
});

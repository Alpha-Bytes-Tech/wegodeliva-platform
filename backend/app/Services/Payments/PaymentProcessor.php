<?php

namespace App\Services\Payments;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\WalletKind;
use App\Events\PaymentFailed;
use App\Events\WalletCredited;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\AuditLogger;
use App\Services\Ledger\WalletLedger;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderStateMachine;
use App\Services\Payments\Gateways\Gateways;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\Gateways\VerifiedTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gateway payments for orders and wallet top-ups (spec 06 §6.2–6.4). The app only ever asks
 * the server to verify; the server asks the gateway with its own secret key and accepts a
 * payment only when status, reference, amount and currency all match and the gateway
 * transaction has not paid for anything else. Applying a result is idempotent, so the app's
 * verify call and the webhook can race safely.
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly OrderStateMachine $machine,
        private readonly OrderLifecycle $lifecycle,
        private readonly WalletLedger $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /** Starts (or restarts) the gateway checkout for an order awaiting payment. */
    public function startOrderPayment(Order $order): Payment
    {
        if ($order->status !== OrderStatus::AwaitingPayment || ! $order->payment_method->isGateway()) {
            throw new ApiException('order_not_payable', 409, meta: ['status' => $order->status->value]);
        }
        $gateway = PaymentGateway::from($order->payment_method->value);
        $this->assertEnabled($gateway);

        $payment = $this->newPayment($order->customer_id, $gateway, $order->total_kobo, ['order_id' => $order->id]);
        $this->initialize($payment, $order->loadMissing('customer')->customer);
        $order->forceFill(['payment_status' => PaymentStatus::Pending])->save();

        return $payment;
    }

    /**
     * Starts a wallet top-up within the KYC tier limits (spec 06 §6.4). A repeated
     * Idempotency-Key returns the first top-up.
     *
     * @return array{0: WalletTopup, 1: Payment, 2: bool} top-up, its payment, whether created now
     */
    public function startTopUp(User $user, int $amount, PaymentGateway $gateway, string $key): array
    {
        $existing = WalletTopup::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->amount_kobo !== $amount || $existing->gateway !== $gateway) {
                throw new ApiException('idempotency_key_reused', 409);
            }

            return [$existing, $existing->payments()->latest('id')->firstOrFail(), false];
        }

        if (! config('wegodeliva.payments.topups_enabled')) {
            throw new ApiException('topups_disabled', 422);
        }
        $this->assertEnabled($gateway);
        $this->assertTopUpAllowed($user, $amount);

        $topup = new WalletTopup;
        $topup->forceFill(['user_id' => $user->id, 'amount_kobo' => $amount, 'gateway' => $gateway, 'status' => 'pending', 'idempotency_key' => $key])->save();

        $payment = $this->newPayment($user->id, $gateway, $amount, ['wallet_topup_id' => $topup->id]);
        $this->initialize($payment, $user);

        return [$topup, $payment, true];
    }

    /** Asks the gateway about the payment and applies the answer. */
    public function verify(Payment $payment): Payment
    {
        if ($payment->status === PaymentAttemptStatus::Succeeded) {
            return $payment;
        }

        return $this->apply($payment, $this->gateways->for($payment->gateway)->verify($payment->reference));
    }

    public function apply(Payment $payment, VerifiedTransaction $result): Payment
    {
        return DB::transaction(function () use ($payment, $result): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PaymentAttemptStatus::Succeeded) {
                return $payment->setRawAttributes($locked->getAttributes(), true);
            }

            if (! $result->successful) {
                if ($result->final) {
                    $locked->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => 'gateway_'.$result->status, 'raw_verification' => $result->toArray(), 'verified_at' => now()])->save();
                    event(new PaymentFailed($locked->id));
                }

                return $payment->setRawAttributes($locked->getAttributes(), true);
            }

            $mismatch = $this->mismatch($locked, $result);
            if ($mismatch !== null) {
                $locked->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => $mismatch, 'raw_verification' => $result->toArray(), 'verified_at' => now()])->save();
                Log::critical('Payment verification mismatch', ['payment' => $locked->uuid, 'reason' => $mismatch]);
                $this->audit->record(null, 'payment.rejected', 'payment', $locked->uuid, after: ['reason' => $mismatch, 'expected_kobo' => $locked->amount_kobo, 'reported' => $result->toArray()]);

                return $payment->setRawAttributes($locked->getAttributes(), true);
            }

            $locked->forceFill([
                'status' => PaymentAttemptStatus::Succeeded,
                'failure_reason' => null,
                'gateway_transaction_id' => $result->transactionId,
                'channel' => $result->channel,
                'raw_verification' => $result->toArray(),
                'verified_at' => now(),
            ])->save();

            $locked->order_id !== null ? $this->orderPaid($locked) : $this->topUpPaid($locked);

            return $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Gives up on unpaid gateway orders and pending top-ups older than the timeout, after one
     * last check with the gateway (spec 04 §4.6). Money that arrives later is still applied:
     * top-ups are credited, and orders that were already cancelled are refunded to the wallet.
     *
     * @return array{orders: int, topups: int}
     */
    public function expireStale(): array
    {
        $cutoff = now()->subMinutes((int) config('wegodeliva.payments.timeout_minutes'));
        $orders = 0;
        $topups = 0;

        Order::query()->where('status', OrderStatus::AwaitingPayment->value)->where('created_at', '<=', $cutoff)->orderBy('id')
            ->each(function (Order $order) use (&$orders): void {
                $this->lastCheck(Payment::query()->where('order_id', $order->id));
                $order->refresh();
                if ($order->status === OrderStatus::AwaitingPayment) {
                    $this->lifecycle->cancel($order, ActorRole::System, null, 'Payment not completed in time', Fault::None);
                    $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
                    $orders++;
                }
            });

        WalletTopup::query()->where('status', 'pending')->where('created_at', '<=', $cutoff)->orderBy('id')
            ->each(function (WalletTopup $topup) use (&$topups): void {
                $this->lastCheck(Payment::query()->where('wallet_topup_id', $topup->id));
                $topup->refresh();
                if ($topup->status === 'pending') {
                    $topup->forceFill(['status' => 'failed'])->save();
                    $topups++;
                }
            });

        return ['orders' => $orders, 'topups' => $topups];
    }

    /** @param  Builder<Payment>  $payments */
    private function lastCheck($payments): void
    {
        foreach ($payments->whereIn('status', ['initiated', 'pending'])->get() as $payment) {
            try {
                $this->verify($payment);
            } catch (GatewayUnavailable $e) {
                Log::warning('Could not re-verify a payment before expiry', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            }
            if ($payment->status->isOpen()) {
                $payment->forceFill(['status' => PaymentAttemptStatus::Abandoned])->save();
            }
        }
    }

    private function mismatch(Payment $payment, VerifiedTransaction $result): ?string
    {
        return match (true) {
            $result->reference !== $payment->reference => 'reference_mismatch',
            $result->amountKobo !== $payment->amount_kobo => 'amount_mismatch',
            $result->currency !== $payment->currency => 'currency_mismatch',
            $result->transactionId === null || Payment::query()
                ->where('gateway', $payment->gateway->value)
                ->where('gateway_transaction_id', $result->transactionId)
                ->whereKeyNot($payment->id)
                ->exists() => 'transaction_reused',
            default => null,
        };
    }

    private function orderPaid(Payment $payment): void
    {
        /** @var Order $order */
        $order = Order::query()->whereKey($payment->order_id)->with('vendor', 'customer')->firstOrFail();

        if ($order->status === OrderStatus::AwaitingPayment) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
            $to = $order->scheduled_for !== null ? OrderStatus::Scheduled : OrderStatus::Placed;
            $this->machine->transition($order, $to, ActorRole::System, reason: 'Payment verified');
            $this->lifecycle->autoAcceptIfEnabled($order);

            return;
        }

        // The order was cancelled (e.g. timed out) or already paid by another attempt before this
        // money arrived: it goes to the customer's wallet in full.
        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
        }
        $this->wallets->credit($order->customer, $payment->amount_kobo, WalletKind::Refund, "payment:{$payment->uuid}:refund", [
            'order_id' => $order->id,
            'gateway' => $payment->gateway->value,
            'note' => "Late payment for order {$order->code} returned to your wallet",
        ], enforceCap: false);
        $this->audit->record(null, 'payment.refunded_late', 'payment', $payment->uuid, after: ['order' => $order->code, 'status' => $order->status->value, 'amount_kobo' => $payment->amount_kobo]);
    }

    private function topUpPaid(Payment $payment): void
    {
        /** @var WalletTopup $topup */
        $topup = WalletTopup::query()->whereKey($payment->wallet_topup_id)->lockForUpdate()->firstOrFail();
        $topup->forceFill(['status' => 'succeeded'])->save();

        // Money that has arrived always lands, even if the wallet filled up meanwhile.
        $credit = $this->wallets->credit($payment->user()->firstOrFail(), $payment->amount_kobo, WalletKind::TopUp, "topup:{$topup->uuid}", [
            'gateway' => $payment->gateway->value,
            'note' => 'Wallet top-up',
        ], enforceCap: false);
        event(new WalletCredited($credit->id));
    }

    private function assertTopUpAllowed(User $user, int $amount): void
    {
        $wallet = $this->wallets->wallet($user);
        $limits = $this->wallets->limits($wallet);
        if ($amount < $limits->minTopUpKobo) {
            throw new ApiException('topup_below_minimum', 422, fields: ['amount_kobo' => ['The minimum top-up is '.Money::format($limits->minTopUpKobo).'.']]);
        }

        // Top-ups still in flight count too, so parallel requests cannot pass the limits together.
        $pending = (int) WalletTopup::query()->where('user_id', $user->id)->where('status', 'pending')->where('created_at', '>=', now()->subMinutes((int) config('wegodeliva.payments.timeout_minutes')))->sum('amount_kobo');

        $dailyLeft = $limits->dailyTopUpKobo - $this->wallets->topUpsToday($wallet) - $pending;
        if ($amount > $dailyLeft) {
            throw new ApiException('topup_daily_limit', 422, meta: ['available' => Money::of(max(0, $dailyLeft)), 'kyc_tier' => $limits->tier]);
        }
        if ($limits->maxBalanceKobo !== null && $wallet->balance_kobo + $pending + $amount > $limits->maxBalanceKobo) {
            throw new ApiException('wallet_balance_cap', 422, meta: ['max_balance' => Money::of($limits->maxBalanceKobo), 'room' => Money::of(max(0, $limits->maxBalanceKobo - $wallet->balance_kobo - $pending)), 'kyc_tier' => $limits->tier]);
        }
    }

    private function assertEnabled(PaymentGateway $gateway): void
    {
        if (! config('wegodeliva.payments.'.$gateway->value)) {
            throw new ApiException('payment_method_unavailable', 422, meta: ['reason' => 'disabled']);
        }
    }

    /** @param  array<string, int>  $target */
    private function newPayment(int $userId, PaymentGateway $gateway, int $amount, array $target): Payment
    {
        $payment = new Payment;
        $payment->forceFill($target + [
            'user_id' => $userId,
            'gateway' => $gateway,
            'reference' => 'WGD-'.Str::upper((string) Str::ulid()),
            'amount_kobo' => $amount,
            'currency' => 'NGN',
            'status' => PaymentAttemptStatus::Initiated,
        ])->save();

        return $payment;
    }

    private function initialize(Payment $payment, User $user): void
    {
        try {
            $url = $this->gateways->for($payment->gateway)->initialize($payment, $user->email, (string) config('wegodeliva.payments.callback_url'));
        } catch (GatewayUnavailable $e) {
            Log::error('Gateway checkout could not start', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            $payment->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => 'gateway_unavailable'])->save();
            throw new ApiException('gateway_unavailable', 503);
        }

        $payment->forceFill(['checkout_url' => $url, 'status' => PaymentAttemptStatus::Pending])->save();
    }
}

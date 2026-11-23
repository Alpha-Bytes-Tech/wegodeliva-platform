<?php

namespace App\Listeners;

use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\Fulfilment;
use App\Enums\VendorMemberRole;
use App\Enums\WalletKind;
use App\Events\DocumentRequested;
use App\Events\OrderAlert;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PayoutUpdated;
use App\Events\RefundIssued;
use App\Events\RiderAssigned;
use App\Events\WalletCredited;
use App\Models\Document;
use App\Models\EarningsAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Notifications\AppNotification;
use App\Support\Money;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about what (spec 08 §8.1). Runs inside the change that raised the event;
 * the notifications themselves are queued and only go out after the transaction commits.
 */
class NotificationRouter
{
    /** Android channel the vendor app plays its repeating new-order sound on. */
    public const NEW_ORDER_CHANNEL = 'new_orders';

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OrderStatusChanged::class, [self::class, 'orderStatusChanged']);
        $events->listen(RiderAssigned::class, [self::class, 'riderAssigned']);
        $events->listen(OrderAlert::class, [self::class, 'orderAlert']);
        $events->listen(RefundIssued::class, [self::class, 'refundIssued']);
        $events->listen(WalletCredited::class, [self::class, 'walletCredited']);
        $events->listen(PaymentFailed::class, [self::class, 'paymentFailed']);
        $events->listen(PayoutUpdated::class, [self::class, 'payoutUpdated']);
        $events->listen(DocumentRequested::class, [self::class, 'documentRequested']);
    }

    public function orderStatusChanged(OrderStatusChanged $event): void
    {
        $order = $this->order($event->orderId);
        $p = $this->params($order);
        $data = ['order' => $order->code];
        $customer = fn (string $key, array $extra = [], bool $mail = false, array $lines = []) => $this->send($order->customer, ClientApp::Customer, "customer.{$key}", $p + $extra, $data, mail: $mail, lines: $lines);

        switch ($event->to) {
            case 'scheduled':
                $customer('order_scheduled', ['time' => $order->scheduled_for?->setTimezone((string) config('wegodeliva.timezone'))->format('D j M, g:i a') ?? '']);
                break;
            case 'placed':
                if (in_array($event->from, [null, 'awaiting_payment'], true)) {
                    $customer('order_placed');
                }
                $this->toVendor($order->vendor, 'new_order', $p + ['items' => (string) $order->items->sum('quantity'), 'total' => Money::format($order->total_kobo)], $data, urgent: true, channel: self::NEW_ORDER_CHANNEL);
                break;
            case 'accepted':
                $customer('order_accepted', ['minutes' => (string) ($order->prep_time_minutes ?? $order->vendor->prep_time_minutes)]);
                break;
            case 'ready':
                if ($order->fulfilment === Fulfilment::Pickup) {
                    $customer('order_ready_pickup');
                } elseif ($order->rider !== null) {
                    $this->send($order->rider, ClientApp::Rider, 'rider.order_ready', $p, $data);
                }
                break;
            case 'picked_up':
                $customer('order_picked_up');
                $this->toVendor($order->vendor, 'order_picked_up', $p, $data);
                break;
            case 'delivered':
                $customer('order_delivered', mail: true, lines: $this->receipt($order));
                $this->toVendor($order->vendor, 'order_delivered', $p, $data);
                if ($order->rider !== null) {
                    $this->send($order->rider, ClientApp::Rider, 'rider.job_delivered', $p, $data);
                }
                break;
            case 'cancelled':
                $reason = $event->reason ?? 'No reason given.';
                if ($event->actor !== 'customer') {
                    $customer('order_cancelled', ['reason' => $reason]);
                }
                if ($event->actor !== 'vendor' && ! in_array($event->from, ['awaiting_payment', 'awaiting_review'], true)) {
                    $this->toVendor($order->vendor, 'order_cancelled', $p + ['reason' => $reason], $data);
                }
                if ($order->rider !== null) {
                    $this->send($order->rider, ClientApp::Rider, 'rider.job_cancelled', $p, $data);
                }
                break;
            case 'failed':
                $customer('order_failed');
                $this->toVendor($order->vendor, 'order_failed', $p, $data);
                break;
        }
    }

    public function riderAssigned(RiderAssigned $event): void
    {
        $order = $this->order($event->orderId);
        if ($order->rider === null) {
            return;
        }
        $p = $this->params($order) + ['rider' => strtok($order->rider->name, ' ') ?: 'Your rider'];
        $data = ['order' => $order->code];
        $this->send($order->customer, ClientApp::Customer, 'customer.rider_assigned', $p, $data);
        $this->toVendor($order->vendor, 'rider_assigned', $p, $data);
        $this->send($order->rider, ClientApp::Rider, 'rider.job_assigned', $p, $data, urgent: true);
    }

    public function orderAlert(OrderAlert $event): void
    {
        $order = $this->order($event->orderId);
        $p = $this->params($order);
        $data = ['order' => $order->code];
        if ($event->alert === 'vendor_slow') {
            $this->toVendor($order->vendor, 'accept_reminder', $p, $data, urgent: true, channel: self::NEW_ORDER_CHANNEL);
        } elseif ($event->alert === 'no_rider') {
            $this->send($order->customer, ClientApp::Customer, 'customer.still_finding_rider', $p, $data);
            $this->toVendor($order->vendor, 'no_rider', $p, $data);
        }
    }

    public function refundIssued(RefundIssued $event): void
    {
        $refund = Refund::query()->findOrFail($event->refundId);
        $order = $this->order($refund->order_id);
        $this->send($order->customer, ClientApp::Customer, 'customer.refund_issued', ['code' => $order->code, 'amount' => Money::format($refund->amount_kobo)], ['order' => $order->code], mail: true);
    }

    public function walletCredited(WalletCredited $event): void
    {
        $entry = WalletTransaction::query()->with('wallet.user')->findOrFail($event->walletTransactionId);
        $key = match ($entry->kind) {
            WalletKind::TopUp => 'customer.topup_credited',
            WalletKind::ReferralReward => 'customer.referral_reward',
            default => null,
        };
        if ($key !== null) {
            $this->send($entry->wallet->user, ClientApp::Customer, $key, ['amount' => Money::format($entry->amount_kobo)], ['wallet_transaction' => $entry->uuid]);
        }
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $payment = Payment::query()->with('order', 'user')->findOrFail($event->paymentId);
        if ($payment->order !== null) {
            $this->send($payment->user, ClientApp::Customer, 'customer.payment_failed', ['code' => $payment->order->code], ['order' => $payment->order->code]);
        }
    }

    public function payoutUpdated(PayoutUpdated $event): void
    {
        $payout = Payout::query()->with('earningsAccount')->findOrFail($event->payoutId);
        $key = match ($payout->status) {
            'processing' => 'payout_sent',
            'paid' => 'payout_paid',
            'failed' => 'payout_failed',
            'rejected' => 'payout_rejected',
            default => null,
        };
        if ($key === null) {
            return;
        }
        $params = ['amount' => Money::format($payout->amount_kobo), 'reason' => $payout->note ?? ''];
        $this->toEarningsOwner($payout->earningsAccount, $key, $params, ['payout' => $payout->uuid], mail: true);
    }

    public function documentRequested(DocumentRequested $event): void
    {
        $document = Document::query()->findOrFail($event->documentId);
        $params = ['kind' => str_replace('_', ' ', $document->kind), 'note' => $document->review_note ?? ''];
        $data = ['document' => $document->uuid];
        if ($document->owner_type === EarningsOwner::Vendor) {
            $this->toVendor(Vendor::query()->findOrFail($document->owner_id), 'document_requested', $params, $data, ownersOnly: true, mail: true);
        } else {
            $this->send(User::query()->findOrFail($document->owner_id), ClientApp::Rider, 'rider.document_requested', $params, $data, mail: true);
        }
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, string>  $data
     */
    private function toEarningsOwner(EarningsAccount $account, string $key, array $params, array $data, bool $mail): void
    {
        if ($account->owner_type === EarningsOwner::Vendor) {
            $this->toVendor(Vendor::withTrashed()->findOrFail($account->owner_id), $key, $params, $data, ownersOnly: true, mail: $mail);
        } else {
            $this->send(User::query()->findOrFail($account->owner_id), ClientApp::Rider, "rider.{$key}", $params, $data, mail: $mail);
        }
    }

    /**
     * Everyone who works at the store (orders), or only its owners (money and documents).
     *
     * @param  array<string, string>  $params
     * @param  array<string, string>  $data
     */
    private function toVendor(Vendor $vendor, string $key, array $params, array $data, bool $ownersOnly = false, bool $mail = false, bool $urgent = false, ?string $channel = null): void
    {
        $relation = $vendor->members();
        if ($ownersOnly) {
            $relation->wherePivot('role', VendorMemberRole::Owner->value);
        }
        $members = $relation->get();
        if ($members->isEmpty()) {
            return;
        }
        Notification::send($members, new AppNotification(ClientApp::Vendor, "vendor.{$key}", $params, $data + ['vendor' => $vendor->uuid], mail: $mail, urgent: $urgent, channel: $channel));
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, string>  $data
     * @param  list<string>  $lines
     */
    private function send(User $user, ClientApp $app, string $key, array $params, array $data, bool $mail = false, bool $urgent = false, array $lines = []): void
    {
        $user->notify(new AppNotification($app, $key, $params, $data, mail: $mail, urgent: $urgent, mailLines: $lines));
    }

    private function order(int $id): Order
    {
        return Order::query()->with('vendor', 'customer', 'rider', 'items')->findOrFail($id);
    }

    /** @return array<string, string> */
    private function params(Order $order): array
    {
        return ['code' => $order->code, 'vendor' => $order->vendor->name];
    }

    /** @return list<string> the order receipt, for the delivery email */
    private function receipt(Order $order): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items;

        return [
            "Receipt for order {$order->code} from {$order->vendor->name}:",
            ...$items->map(fn (OrderItem $i) => "{$i->quantity} × {$i->product_name}: ".Money::format($i->line_total_kobo))->all(),
            'Subtotal: '.Money::format($order->subtotal_kobo),
            ...($order->discount_kobo > 0 ? ['Discount: -'.Money::format($order->discount_kobo)] : []),
            'Delivery: '.Money::format($order->delivery_fee_kobo - $order->delivery_discount_kobo),
            ...($order->service_fee_kobo > 0 ? ['Service fees: '.Money::format($order->service_fee_kobo)] : []),
            ...($order->tax_kobo > 0 ? ['VAT: '.Money::format($order->tax_kobo)] : []),
            ...($order->tip_kobo > 0 ? ['Tip: '.Money::format($order->tip_kobo)] : []),
            'Total: '.Money::format($order->total_kobo).' ('.str_replace('_', ' ', $order->payment_method->value).')',
        ];
    }
}

<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletKind;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Ledger\WalletLedger;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle actions with side effects (spec 04 §4.2, §4.6–4.7): auto-accept, releasing
 * scheduled orders, and cancellation (stock back, coupon use given back, paid amount refunded
 * to the wallet). Vendor, rider and admin fulfilment actions arrive in milestone 2.6.
 */
final class OrderLifecycle
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly WalletLedger $wallets,
    ) {}

    /** A placed order goes straight to accepted when the vendor auto-accepts (spec 04 §4.1). */
    public function autoAcceptIfEnabled(Order $order): Order
    {
        $vendor = $order->loadMissing('vendor')->vendor;
        if ($order->status !== OrderStatus::Placed || ! $vendor->auto_accept_orders) {
            return $order;
        }

        return $this->machine->transition($order, OrderStatus::Accepted, ActorRole::System, reason: 'Auto-accepted', attributes: [
            'prep_time_minutes' => $vendor->prep_time_minutes,
            'estimated_ready_at' => now()->addMinutes($vendor->prep_time_minutes),
        ]);
    }

    /** Releases scheduled orders whose release time has come (runs every minute). */
    public function releaseDueScheduled(): int
    {
        $released = 0;
        Order::query()
            ->where('status', OrderStatus::Scheduled->value)
            ->where('release_at', '<=', now())
            ->with('vendor')
            ->orderBy('release_at')
            ->each(function (Order $order) use (&$released): void {
                $this->machine->transition($order, OrderStatus::Placed, ActorRole::System, reason: 'Scheduled time reached');
                $this->autoAcceptIfEnabled($order);
                $released++;
            });

        return $released;
    }

    /**
     * Cancels the order and undoes what placement did, in one transaction: stock returned,
     * coupon use released, and whatever was paid refunded in full to the customer's wallet.
     * Partial refunds and the fault-based rules for later cancellations arrive in milestone 2.6.
     */
    public function cancel(Order $order, ActorRole $actor, ?User $user, ?string $reason, Fault $fault): Order
    {
        return DB::transaction(function () use ($order, $actor, $user, $reason, $fault): Order {
            $this->machine->transition($order, OrderStatus::Cancelled, $actor, $user, $reason, [
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->value,
                'fault' => $fault,
            ]);

            $this->returnStock($order);
            CouponRedemption::query()->where('order_id', $order->id)->whereNull('released_at')->update(['released_at' => now()]);

            if ($order->payment_status === PaymentStatus::Paid) {
                $this->wallets->credit($order->loadMissing('customer')->customer, $order->total_kobo, WalletKind::Refund, "order:{$order->uuid}:refund", [
                    'order_id' => $order->id,
                    'note' => "Refund for order {$order->code}",
                ], enforceCap: false);
                $order->payment_status = PaymentStatus::Refunded;
                $order->save();
            }

            return $order;
        });
    }

    private function returnStock(Order $order): void
    {
        $items = $order->items()->where('stock_reserved', true)->whereNotNull('product_id')->orderBy('product_id')->get();
        foreach ($items as $item) {
            /** @var OrderItem $item */
            // Only products that still track stock get it back; "unlimited" stays unlimited.
            Product::withTrashed()->whereKey($item->product_id)->whereNotNull('stock_qty')->increment('stock_qty', $item->quantity);
        }
        $order->items()->where('stock_reserved', true)->update(['stock_reserved' => false]);
    }
}

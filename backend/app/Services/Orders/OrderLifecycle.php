<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\EarningsOwner;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Ledger\EarningsLedger;
use App\Services\Pricing\EarningsShare;
use App\Services\Pricing\PricingSettings;
use App\Support\Money;
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
        private readonly Refunds $refunds,
        private readonly EarningsLedger $earnings,
        private readonly PricingSettings $settings,
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
     * coupon use released, and the paid amount refunded to the customer's wallet (spec 04 §4.7,
     * spec 06 §6.6). A cancellation fee, when the customer cancels after the store accepted,
     * is kept back from the refund and paid to the vendor less commission.
     */
    public function cancel(Order $order, ActorRole $actor, ?User $user, ?string $reason, Fault $fault, int $cancellationFeeKobo = 0): Order
    {
        return DB::transaction(function () use ($order, $actor, $user, $reason, $fault, $cancellationFeeKobo): Order {
            $this->machine->transition($order, OrderStatus::Cancelled, $actor, $user, $reason, [
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->value,
                'fault' => $fault,
            ]);

            $this->returnStock($order);
            CouponRedemption::query()->where('order_id', $order->id)->whereNull('released_at')->update(['released_at' => now()]);

            $refundable = $order->refundableKobo();
            $fee = min($cancellationFeeKobo, $refundable);
            if ($refundable - $fee > 0) {
                $this->refunds->refund($order, $refundable - $fee, $reason ?? 'Order cancelled', $fault, $user, "order:{$order->uuid}:refund");
            }
            if ($fee > 0) {
                $commission = Money::percentOf($fee, $order->loadMissing('vendor')->vendor->commission_rate_bp ?? $this->settings->vendorCommissionBp);
                $share = new EarningsShare($fee, $fee, $order->vendor->commission_rate_bp ?? $this->settings->vendorCommissionBp, $commission, Money::percentOf($commission, $this->settings->commissionVatRateBp));
                $this->earnings->recordOrderEarning($this->earnings->account(EarningsOwner::Vendor, $order->vendor_id), $share, "order:{$order->uuid}:cancellation-fee", $order->id);
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

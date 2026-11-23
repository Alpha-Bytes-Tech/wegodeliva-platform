<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\Fulfilment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\RiderAssigned;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\RiderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Vendor, rider and admin actions on an order (spec 04 §4.2, spec 07 §7.2–7.3). Status changes
 * go through the state machine; delivery runs the settlement in the same transaction. Every
 * delivery needs the customer's handover code; three wrong codes lock it until support
 * completes the delivery as an audited admin override.
 */
final class OrderFulfilment
{
    public const MAX_HANDOVER_ATTEMPTS = 3;

    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly OrderLifecycle $lifecycle,
        private readonly DeliverySettlement $settlement,
    ) {}

    public function accept(Order $order, User $by, int $prepMinutes, ActorRole $actor = ActorRole::Vendor): Order
    {
        return $this->machine->transition($order, OrderStatus::Accepted, $actor, $by, attributes: [
            'prep_time_minutes' => $prepMinutes,
            'estimated_ready_at' => now()->addMinutes($prepMinutes),
        ]);
    }

    /** Vendor rejects a new order, or cancels one it accepted: the customer gets everything back. */
    public function vendorCancel(Order $order, User $by, string $reason): Order
    {
        return $this->lifecycle->cancel($order, ActorRole::Vendor, $by, $reason, Fault::Vendor);
    }

    public function ready(Order $order, User $by, ActorRole $actor = ActorRole::Vendor): Order
    {
        return $this->machine->transition($order, OrderStatus::Ready, $actor, $by);
    }

    /**
     * Assigns a rider (spec 07 §7.2 "Manual assignment"). Only before pickup, only delivery
     * orders, and only a rider allowed to deliver for this vendor.
     */
    public function assign(Order $order, User $rider, User $admin): Order
    {
        return DB::transaction(function () use ($order, $rider, $admin): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->fulfilment !== Fulfilment::Delivery || ! in_array($locked->status, [OrderStatus::Placed, OrderStatus::Accepted, OrderStatus::Ready], true)) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $locked->status->value]);
            }

            /** @var RiderProfile|null $profile */
            $profile = RiderProfile::query()->where('user_id', $rider->id)->first();
            if ($profile === null || ! $profile->canDeliverFor($locked)) {
                throw new ApiException('rider_not_eligible', 422);
            }
            $active = Order::query()->where('rider_id', $rider->id)->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Ready->value, OrderStatus::PickedUp->value])->whereKeyNot($locked->id)->count();
            if ($active >= $profile->max_active_orders) {
                throw new ApiException('rider_busy', 409);
            }

            $locked->forceFill(['rider_id' => $rider->id, 'assigned_at' => now(), 'rider_escalation_level' => 0])->save();
            $this->machine->record($locked, $locked->status, $locked->status, ActorRole::Admin, $admin, "Assigned rider {$rider->name}");
            event(new RiderAssigned($locked->id));
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    public function unassign(Order $order, User $admin, string $reason): Order
    {
        return DB::transaction(function () use ($order, $admin, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->rider_id === null || $locked->status->isFinal() || $locked->status === OrderStatus::PickedUp) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $locked->status->value]);
            }

            $locked->forceFill(['rider_id' => null, 'assigned_at' => null])->save();
            $this->machine->record($locked, $locked->status, $locked->status, ActorRole::Admin, $admin, "Rider unassigned: {$reason}");
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    public function pickedUp(Order $order, User $rider): Order
    {
        return $this->machine->transition($order, OrderStatus::PickedUp, ActorRole::Rider, $rider);
    }

    /** The rider delivers against the customer's code (spec 07 §7.3 step 5). */
    public function riderDeliver(Order $order, User $rider, string $code, ?string $collectedVia): Order
    {
        return $this->deliverWithCode($order, $rider, ActorRole::Rider, OrderStatus::PickedUp, $code, $collectedVia);
    }

    /** Self-pickup: the vendor hands the order over against the customer's code (spec 04 §4.2). */
    public function vendorHandover(Order $order, User $by, string $code, ?string $collectedVia): Order
    {
        if ($order->fulfilment !== Fulfilment::Pickup) {
            throw new ApiException('order_status_conflict', 409, meta: ['status' => $order->status->value]);
        }

        return $this->deliverWithCode($order, $by, ActorRole::Vendor, OrderStatus::Ready, $code, $collectedVia);
    }

    /** Support completes a delivery the code could not (audited by the caller). */
    public function adminDeliver(Order $order, User $admin, string $reason, ?string $collectedVia): Order
    {
        $this->requireCollection($order, $collectedVia);

        return DB::transaction(function () use ($order, $admin, $reason, $collectedVia): Order {
            $this->machine->transition($order, OrderStatus::Delivered, ActorRole::Admin, $admin, $reason, ['collected_via' => $collectedVia]);
            $this->settlement->settle($order);

            return $order;
        });
    }

    /** The rider could not deliver (spec 07 §7.3 step 6). Refunds wait for admin review. */
    public function fail(Order $order, User $by, ActorRole $actor, string $reason, ?string $photoPath, bool $customerCalled): Order
    {
        return $this->machine->transition($order, OrderStatus::Failed, $actor, $by, $reason, [
            'failure_reason' => $reason,
            'failure_photo_path' => $photoPath,
            'customer_called' => $customerCalled,
        ]);
    }

    private function deliverWithCode(Order $order, User $by, ActorRole $actor, OrderStatus $from, string $code, ?string $collectedVia): Order
    {
        $this->requireCollection($order, $collectedVia);

        $result = DB::transaction(function () use ($order, $by, $actor, $from, $code, $collectedVia): ?int {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $locked->status->value]);
            }
            if ($locked->handover_attempts >= self::MAX_HANDOVER_ATTEMPTS) {
                throw new ApiException('handover_locked', 423);
            }
            if (! hash_equals($locked->handover_code, $code)) {
                $locked->increment('handover_attempts');

                return self::MAX_HANDOVER_ATTEMPTS - $locked->handover_attempts;
            }

            $this->machine->transition($order, OrderStatus::Delivered, $actor, $by, attributes: ['collected_via' => $collectedVia]);
            $this->settlement->settle($order);

            return null;
        });

        // The wrong attempt is committed before answering, so it counts.
        if ($result !== null) {
            throw new ApiException($result > 0 ? 'handover_code_invalid' : 'handover_locked', $result > 0 ? 422 : 423, meta: ['attempts_left' => $result]);
        }

        return $order;
    }

    private function requireCollection(Order $order, ?string $collectedVia): void
    {
        if ($order->payment_method === PaymentMethod::PayOnDelivery && $collectedVia === null) {
            throw new ApiException('validation_failed', 400, fields: ['collected_via' => ['Say whether the customer paid by cash or transfer.']]);
        }
    }
}

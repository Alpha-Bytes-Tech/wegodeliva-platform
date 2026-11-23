<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\OrderStatus as S;
use App\Events\OrderStatusChanged;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The order state machine (spec 04 §4.2). Every status change goes through `transition`, which
 * checks the transition table for the actor, locks the order, records the history entry and the
 * status timestamp in one transaction. Final statuses never change. Side effects (stock,
 * refunds, earnings) belong to the callers that run the business action.
 */
final class OrderStateMachine
{
    /**
     * from => [to => actors allowed]. Admins may also cancel or fail any non-final order.
     *
     * @var array<string, array<string, list<ActorRole>>>
     */
    private const TABLE = [
        'awaiting_payment' => [
            'placed' => [ActorRole::System, ActorRole::Admin],
            'scheduled' => [ActorRole::System, ActorRole::Admin],
            'requested' => [ActorRole::System, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::System, ActorRole::Admin],
        ],
        'awaiting_review' => [
            'awaiting_payment' => [ActorRole::Vendor, ActorRole::Admin],
            'placed' => [ActorRole::Vendor, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::Vendor, ActorRole::System, ActorRole::Admin],
        ],
        'scheduled' => [
            'placed' => [ActorRole::System, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::Vendor, ActorRole::Admin],
        ],
        'placed' => [
            'accepted' => [ActorRole::Vendor, ActorRole::System, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::Vendor, ActorRole::System, ActorRole::Admin],
        ],
        'accepted' => [
            'ready' => [ActorRole::Vendor, ActorRole::Admin],
            'cancelled' => [ActorRole::Vendor, ActorRole::Admin],
        ],
        'ready' => [
            'picked_up' => [ActorRole::Vendor, ActorRole::Rider, ActorRole::Admin],
            // Self-pickup: the vendor hands over against the customer's code.
            'delivered' => [ActorRole::Vendor, ActorRole::Admin],
            'cancelled' => [ActorRole::System, ActorRole::Admin],
        ],
        'picked_up' => [
            'delivered' => [ActorRole::Rider, ActorRole::Admin],
            'failed' => [ActorRole::Rider, ActorRole::Admin],
        ],
        // Service bookings (spec 04 §4.8).
        'requested' => [
            'confirmed' => [ActorRole::Vendor, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::Vendor, ActorRole::System, ActorRole::Admin],
        ],
        'confirmed' => [
            'in_progress' => [ActorRole::Vendor, ActorRole::Admin],
            'cancelled' => [ActorRole::Customer, ActorRole::Vendor, ActorRole::Admin],
        ],
        'in_progress' => [
            'completed' => [ActorRole::Vendor, ActorRole::Admin],
        ],
        // The provider is paid once the customer confirms; a reported problem goes to support.
        'disputed' => [
            'completed' => [ActorRole::Admin],
        ],
    ];

    /** A completed booking is final except for a problem the customer reports. */
    private const REOPEN = ['completed' => ['disputed' => [ActorRole::Customer]]];

    public function allows(S $from, S $to, ActorRole $actor): bool
    {
        if ($from->isFinal()) {
            return in_array($actor, self::REOPEN[$from->value][$to->value] ?? [], true);
        }
        if ($actor === ActorRole::Admin && ($to === S::Cancelled || $to === S::Failed)) {
            return true;
        }

        return in_array($actor, self::TABLE[$from->value][$to->value] ?? [], true);
    }

    /**
     * Moves the order on, or answers 409 `order_status_conflict` if the transition is not allowed
     * for this actor from the order's current status (re-read under lock).
     *
     * @param  array<string, mixed>  $attributes  extra order columns to set in the same write
     */
    public function transition(Order $order, S $to, ActorRole $actor, ?User $user = null, ?string $reason = null, array $attributes = []): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $user, $reason, $attributes): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $this->allows($from, $to, $actor)) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $from->value]);
            }

            $locked->forceFill($attributes);
            $locked->status = $to;
            $column = $to->timestampColumn();
            if ($column !== null) {
                $locked->setAttribute($column, now());
            }
            $locked->save();

            $this->record($locked, $from, $to, $actor, $user, $reason);
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /** Records the first status of a new order (no "from"). */
    public function record(Order $order, ?S $from, S $to, ActorRole $actor, ?User $user = null, ?string $reason = null): void
    {
        $entry = new OrderStatusHistory;
        $entry->forceFill([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $user?->id,
            'actor_role' => $actor,
            'reason' => $reason,
        ])->save();

        // Status changes (not notes such as rider assignment, which keep the status) notify people.
        if ($from !== $to) {
            event(new OrderStatusChanged($order->id, $from?->value, $to->value, $actor->value, $reason));
        }
    }
}

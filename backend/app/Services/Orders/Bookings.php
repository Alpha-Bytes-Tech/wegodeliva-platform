<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderAlert;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service booking actions (spec 04 §4.8): the provider confirms, starts against the customer's
 * code and completes; the customer confirms completion or reports a problem; the provider's
 * earnings are credited only once completion is confirmed (owner decision 2026-11-23), by the
 * customer, automatically after the window, or by support releasing a dispute. Cancelling a
 * confirmed booking late costs the customer a share of the service price (default 50%).
 */
final class Bookings
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly OrderLifecycle $lifecycle,
        private readonly DeliverySettlement $settlement,
    ) {}

    public function confirm(Order $order, User $by): Order
    {
        return $this->machine->transition($this->booking($order), OrderStatus::Confirmed, ActorRole::Vendor, $by);
    }

    /** The provider starts the job with the code the customer gives them (three tries). */
    public function start(Order $order, User $by, string $code): Order
    {
        $this->booking($order);
        $left = DB::transaction(function () use ($order, $by, $code): ?int {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::Confirmed) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $locked->status->value]);
            }
            $opensAt = $locked->starts_at?->copy()->subMinutes((int) config('wegodeliva.bookings.start_early_minutes'));
            if ($opensAt !== null && now()->lt($opensAt)) {
                throw new ApiException('booking_not_started', 409, meta: ['can_start_at' => $opensAt->toIso8601String()]);
            }
            if ($locked->handover_attempts >= OrderFulfilment::MAX_HANDOVER_ATTEMPTS) {
                throw new ApiException('handover_locked', 423);
            }
            if (! hash_equals($locked->handover_code, $code)) {
                $locked->increment('handover_attempts');

                return OrderFulfilment::MAX_HANDOVER_ATTEMPTS - $locked->handover_attempts;
            }

            $this->machine->transition($order, OrderStatus::InProgress, ActorRole::Vendor, $by);

            return null;
        });

        if ($left !== null) {
            throw new ApiException($left > 0 ? 'handover_code_invalid' : 'handover_locked', $left > 0 ? 422 : 423, meta: ['attempts_left' => $left]);
        }

        return $order;
    }

    public function complete(Order $order, User $by, ?string $collectedVia): Order
    {
        $this->booking($order);
        if ($order->payment_method === PaymentMethod::PayOnDelivery && $collectedVia === null) {
            throw new ApiException('validation_failed', 400, fields: ['collected_via' => ['Say whether the customer paid by cash or transfer.']]);
        }

        return $this->machine->transition($order, OrderStatus::Completed, ActorRole::Vendor, $by, attributes: ['collected_via' => $collectedVia]);
    }

    /** The customer (or the system, after the window) confirms the job was done: the provider is paid. */
    public function confirmCompletion(Order $order, ?User $customer): Order
    {
        $this->booking($order);

        return DB::transaction(function () use ($order, $customer): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::Completed || $locked->completion_confirmed_at !== null) {
                throw new ApiException('order_status_conflict', 409, meta: ['status' => $locked->status->value]);
            }

            $locked->forceFill(['completion_confirmed_at' => now()])->save();
            $this->machine->record($locked, OrderStatus::Completed, OrderStatus::Completed, $customer === null ? ActorRole::System : ActorRole::Customer, $customer, 'Completion confirmed');
            $this->settlement->settle($locked);
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /** The customer reports a problem before completion is confirmed; support decides. */
    public function dispute(Order $order, User $customer, string $reason): Order
    {
        $this->booking($order);
        if ($order->completion_confirmed_at !== null || ($order->completed_at !== null && $order->completed_at->lt(now()->subHours((int) config('wegodeliva.bookings.auto_confirm_hours'))))) {
            throw new ApiException('order_status_conflict', 409, meta: ['status' => $order->status->value]);
        }

        $this->machine->transition($order, OrderStatus::Disputed, ActorRole::Customer, $customer, $reason, ['dispute_reason' => $reason]);
        event(new OrderAlert($order->id, 'booking_disputed'));

        return $order;
    }

    /** Support settles a dispute: release pays the provider; refund cancels and refunds the customer. */
    public function resolve(Order $order, User $admin, string $outcome, string $note): Order
    {
        $this->booking($order);
        if ($order->status !== OrderStatus::Disputed) {
            throw new ApiException('order_status_conflict', 409, meta: ['status' => $order->status->value]);
        }

        if ($outcome === 'refund') {
            return $this->lifecycle->cancel($order, ActorRole::Admin, $admin, $note, Fault::Vendor);
        }

        return DB::transaction(function () use ($order, $admin, $note): Order {
            $this->machine->transition($order, OrderStatus::Completed, ActorRole::Admin, $admin, $note);

            return $this->confirmCompletion($order, null);
        });
    }

    /** The late-cancellation fee if the customer cancelled now (spec 13 "Services and chat"). */
    public function cancellationFee(Order $order): int
    {
        $cfg = config('wegodeliva.bookings');
        $late = $order->status === OrderStatus::Confirmed
            && $order->starts_at !== null
            && now()->gte($order->starts_at->copy()->subHours((int) $cfg['free_cancel_hours']));

        return $late ? Money::percentOf($order->subtotal_kobo - $order->discount_kobo, (int) $cfg['late_cancel_fee_bp']) : 0;
    }

    public function customerCancel(Order $order, User $customer, string $reason): Order
    {
        return $this->lifecycle->cancel($this->booking($order), ActorRole::Customer, $customer, $reason, Fault::Customer, $this->cancellationFee($order));
    }

    /**
     * Background checks (every minute): requests the provider did not answer in time are
     * cancelled with a full refund; reminders go out a day and an hour before; completed jobs
     * nobody disputed are confirmed after the window.
     *
     * @return array{timeouts: int, reminders: int, auto_confirmed: int}
     */
    public function monitor(): array
    {
        $cfg = config('wegodeliva.bookings');
        $counts = ['timeouts' => 0, 'reminders' => 0, 'auto_confirmed' => 0];

        Order::query()->where('type', Order::TYPE_SERVICE)->where('status', OrderStatus::Requested->value)->whereNotNull('placed_at')->with('vendor')->orderBy('id')
            ->each(function (Order $order) use ($cfg, &$counts): void {
                if (now()->lt($this->acceptBy($order, $cfg))) {
                    return;
                }
                $this->lifecycle->cancel($order, ActorRole::System, null, 'The provider did not accept in time', Fault::Vendor);
                $counts['timeouts']++;
            });

        foreach (['reminded_day_at' => 24 * 60, 'reminded_hour_at' => 60] as $column => $minutes) {
            Order::query()->where('type', Order::TYPE_SERVICE)->where('status', OrderStatus::Confirmed->value)
                ->whereNull($column)->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($minutes))->orderBy('id')
                ->each(function (Order $order) use ($column, &$counts): void {
                    // An hour's notice makes the day's reminder pointless.
                    $order->forceFill(['reminded_day_at' => $order->reminded_day_at ?? now(), $column => now()])->save();
                    event(new OrderAlert($order->id, 'booking_reminder'));
                    $counts['reminders']++;
                });
        }

        Order::query()->where('type', Order::TYPE_SERVICE)->where('status', OrderStatus::Completed->value)->whereNull('completion_confirmed_at')
            ->where('completed_at', '<=', now()->subHours((int) $cfg['auto_confirm_hours']))->orderBy('id')
            ->each(function (Order $order) use (&$counts): void {
                $this->confirmCompletion($order, null);
                $counts['auto_confirmed']++;
            });

        return $counts;
    }

    /** @param  array<string, mixed>  $cfg */
    private function acceptBy(Order $order, array $cfg): Carbon
    {
        $placed = $order->placed_at ?? now();
        $shortNotice = $order->starts_at !== null && $order->starts_at->lte($placed->copy()->addHours((int) $cfg['short_notice_hours']));

        return $placed->copy()->addMinutes((int) ($shortNotice ? $cfg['short_notice_accept_minutes'] : $cfg['accept_timeout_minutes']));
    }

    private function booking(Order $order): Order
    {
        if (! $order->isBooking()) {
            throw new ApiException('order_status_conflict', 409, meta: ['status' => $order->status->value]);
        }

        return $order;
    }
}

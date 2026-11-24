<?php

namespace App\Services\Orders;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\Fulfilment;
use App\Enums\OrderStatus;
use App\Events\OrderAlert;
use App\Models\Order;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Background checks on live orders (spec 04 §4.6): stores that do not accept in time, delivery
 * orders without a rider, and orders stuck for a day. Each alert fires once per order and goes
 * to the log and the audit log; push and email alerts arrive with notifications (2.8).
 * Opening and closing needs no job: a store's open state is worked out from its hours on read.
 *
 * @phpstan-type Counts array{vendor_alerts: int, vendor_timeouts: int, rider_alerts: int, stale_alerts: int}
 */
final class OrderMonitor
{
    public function __construct(private readonly OrderLifecycle $lifecycle, private readonly AuditLogger $audit) {}

    /** @return Counts */
    public function run(): array
    {
        $cfg = config('wegodeliva.orders');
        $counts = ['vendor_alerts' => 0, 'vendor_timeouts' => 0, 'rider_alerts' => 0, 'stale_alerts' => 0];

        Order::query()->where('status', OrderStatus::Placed->value)->where('placed_at', '<=', now()->subMinutes((int) $cfg['vendor_timeout_minutes']))->with('vendor')->orderBy('id')
            ->each(function (Order $order) use (&$counts): void {
                $this->lifecycle->cancel($order, ActorRole::System, null, 'The store did not accept in time', Fault::Vendor);
                $this->alert($order, 'order.vendor_timeout');
                $counts['vendor_timeouts']++;
            });

        Order::query()->where('status', OrderStatus::Placed->value)->whereNull('vendor_alerted_at')->where('placed_at', '<=', now()->subMinutes((int) $cfg['vendor_alert_minutes']))->orderBy('id')
            ->each(function (Order $order) use (&$counts): void {
                $order->forceFill(['vendor_alerted_at' => now()])->save();
                $this->alert($order, 'order.vendor_slow');
                event(new OrderAlert($order->id, 'vendor_slow'));
                $counts['vendor_alerts']++;
            });

        foreach ([1 => (int) $cfg['rider_alert_minutes'], 2 => (int) $cfg['rider_escalate_minutes']] as $level => $minutes) {
            Order::query()
                ->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Ready->value])
                ->where('fulfilment', Fulfilment::Delivery->value)
                ->whereNull('rider_id')
                ->where('rider_escalation_level', '<', $level)
                ->where('accepted_at', '<=', now()->subMinutes($minutes))
                ->orderBy('id')
                ->each(function (Order $order) use ($level, &$counts): void {
                    $order->forceFill(['rider_escalation_level' => $level])->save();
                    $this->alert($order, $level === 1 ? 'order.no_rider' : 'order.no_rider_escalated');
                    if ($level === 1) {
                        event(new OrderAlert($order->id, 'no_rider'));
                    }
                    $counts['rider_alerts']++;
                });
        }

        Order::query()
            ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Failed->value])
            // Bookings can sit confirmed for weeks; their own checks run in Bookings::monitor.
            ->where('type', '!=', Order::TYPE_SERVICE)
            ->whereNull('stale_alerted_at')
            ->where('created_at', '<=', now()->subHours((int) $cfg['stale_hours']))
            ->orderBy('id')
            ->each(function (Order $order) use (&$counts): void {
                $order->forceFill(['stale_alerted_at' => now()])->save();
                $this->alert($order, 'order.stale');
                $counts['stale_alerts']++;
            });

        return $counts;
    }

    private function alert(Order $order, string $action): void
    {
        Log::warning('Order needs attention', ['order' => $order->code, 'alert' => $action, 'status' => $order->status->value]);
        $this->audit->record(null, $action, 'order', $order->code, after: ['status' => $order->status->value]);
    }
}

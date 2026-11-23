<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\Catalogue\VendorSchedule;
use App\Services\Pricing\ServicePricer;
use Illuminate\Support\Carbon;

/**
 * When a service can be booked (spec 04 §4.8): inside the provider's opening hours, and while
 * fewer bookings than the service's capacity overlap the slot. Bookings waiting for payment or
 * for the provider hold their slot. Expects `vendor.hours` loaded on the service.
 */
final class BookingSlots
{
    /** Statuses whose bookings hold their slot. */
    private const HOLDING = [OrderStatus::AwaitingPayment, OrderStatus::Requested, OrderStatus::Confirmed, OrderStatus::InProgress];

    public function __construct(private readonly VendorSchedule $schedule, private readonly ServicePricer $pricer) {}

    /** Open at the start and, for services by the hour or fixed length, until the end. */
    public function fits(Product $service, Carbon $start, Carbon $end): bool
    {
        $vendor = $service->vendor;
        if (! $this->schedule->scheduledOpen($vendor, $start)) {
            return false;
        }

        return in_array($service->pricing_unit, ['day', 'month'], true) || $this->schedule->scheduledOpen($vendor, $end->copy()->subMinute());
    }

    public function hasRoom(Product $service, Carbon $start, Carbon $end): bool
    {
        return $this->overlaps($this->held($service, $start, $end), $start, $end) < ($service->capacity ?? 1);
    }

    /**
     * Start times on a local day (Africa/Lagos) that can be booked for this many units.
     *
     * @return list<string> ISO 8601 start times
     */
    public function available(Product $service, Carbon $day, int $units): array
    {
        $cfg = config('wegodeliva.bookings');
        $tz = (string) config('wegodeliva.timezone');
        $from = $day->copy()->setTimezone($tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();
        $earliest = now()->addMinutes((int) $cfg['min_lead_minutes']);
        $latest = now()->addDays((int) $cfg['max_advance_days']);
        $step = max(5, (int) $cfg['slot_step_minutes']);

        $held = $this->held($service, $from, $this->pricer->endsAt($service, $to, $units, (int) $cfg['default_duration_minutes']));
        $slots = [];
        for ($start = $from->copy(); $start->lt($to); $start->addMinutes($step)) {
            $end = $this->pricer->endsAt($service, $start, $units, (int) $cfg['default_duration_minutes']);
            if ($start->gte($earliest) && $start->lte($latest) && $this->fits($service, $start, $end) && $this->overlaps($held, $start, $end) < ($service->capacity ?? 1)) {
                $slots[] = $start->toIso8601String();
            }
        }

        return $slots;
    }

    /** @return list<array{0: Carbon, 1: Carbon}> bookings of this service overlapping the window */
    private function held(Product $service, Carbon $from, Carbon $to): array
    {
        return Order::query()
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::HOLDING))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->whereHas('items', fn ($q) => $q->where('product_id', $service->id))
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Order $o) => [$o->starts_at ?? $from, $o->ends_at ?? $to])
            ->values()
            ->all();
    }

    /** @param  list<array{0: Carbon, 1: Carbon}>  $held */
    private function overlaps(array $held, Carbon $start, Carbon $end): int
    {
        return count(array_filter($held, fn (array $b) => $b[0]->lt($end) && $b[1]->gt($start)));
    }
}

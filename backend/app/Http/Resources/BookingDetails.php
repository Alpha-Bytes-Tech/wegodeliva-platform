<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\Bookings;
use App\Support\Money;

/**
 * The booking part of an order (spec 09 "Booking details", spec 10 "Booking details"): when and
 * where, and for the customer what they can do next and what cancelling would cost now.
 */
final class BookingDetails
{
    /** @return array<string, mixed> */
    public static function for(Order $order, bool $forCustomer): array
    {
        $window = (int) config('wegodeliva.bookings.auto_confirm_hours');
        $awaitingConfirmation = $order->status === OrderStatus::Completed && $order->completion_confirmed_at === null;

        return [
            'units' => $order->units,
            'starts_at' => $order->starts_at?->toIso8601String(),
            'ends_at' => $order->ends_at?->toIso8601String(),
            'location' => $order->service_location,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'started_at' => $order->started_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'completion_confirmed_at' => $order->completion_confirmed_at?->toIso8601String(),
            'dispute_reason' => $order->dispute_reason,
            'auto_confirms_at' => $awaitingConfirmation ? $order->completed_at?->copy()->addHours($window)->toIso8601String() : null,
            ...($forCustomer ? [
                'can_confirm' => $awaitingConfirmation,
                'can_report_problem' => $awaitingConfirmation,
                'cancellation_fee' => Money::of(app(Bookings::class)->cancellationFee($order)),
            ] : []),
        ];
    }
}

<?php

namespace App\Enums;

/** Order statuses (spec 04 §4.2). */
enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingReview = 'awaiting_review';
    case Scheduled = 'scheduled';
    case Placed = 'placed';
    case Accepted = 'accepted';
    case Ready = 'ready';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Failed], true);
    }

    /** The order's timestamp column for entering this status, if it has one. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Placed => 'placed_at',
            self::Accepted => 'accepted_at',
            self::Ready => 'ready_at',
            self::PickedUp => 'picked_up_at',
            self::Delivered => 'delivered_at',
            self::Cancelled => 'cancelled_at',
            self::Failed => 'failed_at',
            default => null,
        };
    }
}

<?php

namespace App\Services\Catalogue;

use App\Enums\OpenState;
use App\Models\Vendor;
use App\Models\VendorHour;
use Illuminate\Support\Carbon;

/**
 * Whether a vendor is open (spec 10 "Store status", spec 04 §4.6 "Vendor auto open/close").
 * A manual override (open / busy / closed) wins until it expires; otherwise the weekly hours,
 * read in the platform timezone, decide. A vendor with no hours is closed.
 */
final class VendorSchedule
{
    public function stateAt(Vendor $vendor, Carbon $at): OpenState
    {
        if ($vendor->manual_state !== null && ($vendor->manual_state_until === null || $vendor->manual_state_until->isAfter($at))) {
            return $vendor->manual_state;
        }

        return $this->scheduledOpen($vendor, $at) ? OpenState::Open : OpenState::Closed;
    }

    public function scheduledOpen(Vendor $vendor, Carbon $at): bool
    {
        $local = $at->copy()->setTimezone((string) config('wegodeliva.timezone'));
        $today = (int) $local->dayOfWeek;
        $yesterday = ($today + 6) % 7;
        $time = $local->format('H:i:s');

        /** @var iterable<VendorHour> $hours */
        $hours = $vendor->hours;
        foreach ($hours as $period) {
            $overnight = $period->closes_at <= $period->opens_at;
            if ($period->weekday === $today) {
                if ($time >= $period->opens_at && ($overnight || $time < $period->closes_at)) {
                    return true;
                }
            }
            if ($overnight && $period->weekday === $yesterday && $time < $period->closes_at) {
                return true;
            }
        }

        return false;
    }
}

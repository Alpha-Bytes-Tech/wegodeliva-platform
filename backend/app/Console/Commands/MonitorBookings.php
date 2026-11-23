<?php

namespace App\Console\Commands;

use App\Services\Orders\Bookings;
use Illuminate\Console\Command;

/** Booking acceptance timeouts, reminders and auto-confirmation (spec 04 §4.8). */
class MonitorBookings extends Command
{
    protected $signature = 'bookings:monitor';

    protected $description = 'Time out unanswered booking requests, send reminders and auto-confirm completed bookings';

    public function handle(Bookings $bookings): int
    {
        $counts = $bookings->monitor();
        $this->info(sprintf('Timed out %d, reminders %d, auto-confirmed %d.', $counts['timeouts'], $counts['reminders'], $counts['auto_confirmed']));

        return self::SUCCESS;
    }
}

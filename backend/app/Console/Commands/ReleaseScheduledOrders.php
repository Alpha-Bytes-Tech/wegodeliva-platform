<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderLifecycle;
use Illuminate\Console\Command;

/** Sends scheduled orders to their vendors when their release time comes (spec 04 §4.6). */
class ReleaseScheduledOrders extends Command
{
    protected $signature = 'orders:release-scheduled';

    protected $description = 'Release scheduled orders whose release time has come';

    public function handle(OrderLifecycle $lifecycle): int
    {
        $count = $lifecycle->releaseDueScheduled();
        $this->info("Released {$count} scheduled order(s).");

        return self::SUCCESS;
    }
}

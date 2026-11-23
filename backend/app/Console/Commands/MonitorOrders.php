<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderMonitor;
use Illuminate\Console\Command;

/** Vendor acceptance timeouts, no-rider and stale-order alerts (spec 04 §4.6). */
class MonitorOrders extends Command
{
    protected $signature = 'orders:monitor';

    protected $description = 'Time out unaccepted orders and raise alerts for orders that need attention';

    public function handle(OrderMonitor $monitor): int
    {
        $counts = $monitor->run();
        $this->info(sprintf('Timed out %d, store alerts %d, rider alerts %d, stale alerts %d.', $counts['vendor_timeouts'], $counts['vendor_alerts'], $counts['rider_alerts'], $counts['stale_alerts']));

        return self::SUCCESS;
    }
}

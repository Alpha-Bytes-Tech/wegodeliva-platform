<?php

namespace App\Console\Commands;

use App\Services\Payments\Payouts;
use Illuminate\Console\Command;

/** Checks payouts still processing after five minutes, in case the transfer webhook never came. */
class SyncPayouts extends Command
{
    protected $signature = 'payouts:sync';

    protected $description = 'Check processing payouts with the gateway';

    public function handle(Payouts $payouts): int
    {
        $this->info('Settled '.$payouts->syncStale().' payout(s).');

        return self::SUCCESS;
    }
}

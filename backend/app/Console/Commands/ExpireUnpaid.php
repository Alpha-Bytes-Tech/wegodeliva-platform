<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentProcessor;
use Illuminate\Console\Command;

/** Cancels unpaid gateway orders and fails pending top-ups after one last check (spec 04 §4.6). */
class ExpireUnpaid extends Command
{
    protected $signature = 'payments:expire';

    protected $description = 'Give up on gateway payments not completed in time';

    public function handle(PaymentProcessor $payments): int
    {
        $result = $payments->expireStale();
        $this->info("Cancelled {$result['orders']} unpaid order(s); failed {$result['topups']} top-up(s).");

        return self::SUCCESS;
    }
}

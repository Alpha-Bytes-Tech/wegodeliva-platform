<?php

namespace App\Console\Commands;

use App\Services\Payments\Reconciliation;
use Illuminate\Console\Command;

/** Daily check of what WeGoDeliva owes its users against the gateway balances (spec 06 §6.4.1). */
class Reconcile extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Compare ledger liabilities with the money held at the gateways';

    public function handle(Reconciliation $reconciliation): int
    {
        $report = $reconciliation->run();
        $this->info("Reconciliation {$report->status}: owed {$report->owed_kobo} kobo, held ".($report->held_kobo ?? 'unknown').' kobo.');

        return $report->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}

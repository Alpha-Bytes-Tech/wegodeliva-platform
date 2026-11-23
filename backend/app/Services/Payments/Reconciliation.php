<?php

namespace App\Services\Payments;

use App\Enums\PaymentGateway;
use App\Models\ReconciliationReport;
use App\Services\AuditLogger;
use App\Services\Payments\Gateways\Gateways;
use App\Services\Payments\Gateways\GatewayUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily check that the money held at Paystack and Flutterwave covers what WeGoDeliva owes its
 * users (spec 06 §6.4.1): positive customer wallet balances plus vendor and rider earnings net
 * of commission, VAT on commission and cash owed. A shortfall is logged as critical and written
 * to the audit log. Exact equality also needs the platform's own revenue and in-flight
 * settlements, which arrive with payouts (milestone 2.7).
 */
final class Reconciliation
{
    public function __construct(private readonly Gateways $gateways, private readonly AuditLogger $audit) {}

    public function run(): ReconciliationReport
    {
        $wallets = (int) DB::table('wallets')->where('balance_kobo', '>', 0)->sum('balance_kobo');
        $earnings = (int) DB::table('earnings_accounts')
            ->selectRaw('COALESCE(SUM(GREATEST(balance_kobo - commission_due_kobo - commission_vat_due_kobo - cash_owed_kobo, 0)), 0) AS owed')
            ->value('owed');
        $owed = $wallets + $earnings;

        $balances = [];
        foreach (PaymentGateway::cases() as $gateway) {
            if ((string) config("services.{$gateway->value}.secret_key") === '') {
                continue;
            }
            try {
                $balances[$gateway->value] = $this->gateways->for($gateway)->balanceKobo();
            } catch (GatewayUnavailable $e) {
                Log::warning('Gateway balance unavailable for reconciliation', ['gateway' => $gateway->value, 'error' => $e->getMessage()]);
                $balances[$gateway->value] = null;
            }
        }

        $complete = $balances !== [] && ! in_array(null, $balances, true);
        $held = $complete ? array_sum($balances) : null;
        $shortfall = $held === null ? null : $owed - $held;
        $status = match (true) {
            ! $complete => 'incomplete',
            $shortfall > 0 => 'shortfall',
            default => 'ok',
        };

        $today = now()->toDateString();
        $report = ReconciliationReport::query()->whereDate('run_on', $today)->first() ?? new ReconciliationReport;
        $report->forceFill([
            'run_on' => $today,
            'ledger' => ['customer_wallets_kobo' => $wallets, 'earnings_owed_kobo' => $earnings],
            'gateways' => $balances,
            'owed_kobo' => $owed,
            'held_kobo' => $held,
            'shortfall_kobo' => $shortfall,
            'status' => $status,
        ])->save();

        if ($status !== 'ok') {
            Log::critical('Reconciliation needs attention', ['status' => $status, 'owed_kobo' => $owed, 'held_kobo' => $held]);
            $this->audit->record(null, 'reconciliation.'.$status, 'reconciliation', (string) $report->id, after: ['owed_kobo' => $owed, 'held_kobo' => $held, 'shortfall_kobo' => $shortfall]);
        }

        return $report;
    }
}

<?php

namespace App\Services\Payments;

use App\Enums\EarningsOwner;
use App\Enums\PaymentGateway;
use App\Events\PayoutUpdated;
use App\Exceptions\ApiException;
use App\Models\EarningsAccount;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Ledger\EarningsLedger;
use App\Services\Payments\Gateways\Gateways;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vendor and rider payouts (spec 06 §6.7). A request holds the amount; approval settles
 * commission, its VAT and cash owed, then sends the transfer; the gateway's answer (asked by
 * us, prompted by its webhook or the sync job) pays or releases the hold. Bank accounts are
 * checked with the gateway's account-resolve API, and a name that does not match the vendor
 * or rider waits for an admin.
 */
final class Payouts
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly EarningsLedger $earnings,
        private readonly AuditLogger $audit,
    ) {}

    public function gateway(): PaymentGateway
    {
        return PaymentGateway::from((string) config('wegodeliva.payouts.gateway'));
    }

    /** @return list<array{code: string, name: string}> */
    public function banks(): array
    {
        return Cache::remember('payouts:banks:'.$this->gateway()->value, now()->addDay(), fn () => $this->unavailableAs503(fn () => $this->gateways->transfers($this->gateway())->banks()));
    }

    /**
     * Adds a bank account after the bank confirms who holds it.
     *
     * @param  list<string>  $ownerNames  names the account holder may reasonably go by
     */
    public function addAccount(EarningsOwner $type, int $ownerId, string $bankCode, string $accountNumber, array $ownerNames): PayoutAccount
    {
        $bank = collect($this->banks())->firstWhere('code', $bankCode)
            ?? throw new ApiException('validation_failed', 400, fields: ['bank_code' => ['Choose a bank from the list.']]);

        $name = $this->unavailableAs503(fn () => $this->gateways->transfers($this->gateway())->resolveAccount($bankCode, $accountNumber));
        if ($name === null) {
            throw new ApiException('bank_account_not_found', 422, fields: ['account_number' => ['The bank does not recognise this account number.']]);
        }

        $exists = PayoutAccount::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->where('bank_code', $bankCode)->where('account_number', $accountNumber)->exists();
        if ($exists) {
            throw new ApiException('bank_account_exists', 409);
        }

        return DB::transaction(function () use ($type, $ownerId, $bank, $accountNumber, $name, $ownerNames): PayoutAccount {
            $first = ! PayoutAccount::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->exists();
            $account = new PayoutAccount;
            $account->forceFill([
                'owner_type' => $type,
                'owner_id' => $ownerId,
                'bank_code' => $bank['code'],
                'bank_name' => $bank['name'],
                'account_number' => $accountNumber,
                'account_name' => $name,
                'status' => self::namesMatch($name, $ownerNames) ? 'verified' : 'needs_review',
                'is_default' => $first,
            ])->save();

            return $account;
        });
    }

    /**
     * Reasonable match: some word of three or more letters in the bank's name also appears in
     * one of the owner's names ("ADEBAYO OLUWASEUN JOHN" matches "Seun Adebayo").
     *
     * @param  list<string>  $ownerNames
     */
    public static function namesMatch(string $accountName, array $ownerNames): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^a-z]+/', strtolower($s)) ?: [], fn (string $w) => strlen($w) >= 3);
        $owner = array_merge(...array_map($words, $ownerNames ?: ['']));

        return array_intersect($words($accountName), $owner) !== [];
    }

    public function request(EarningsAccount $account, PayoutAccount $bank, int $amount, User $by, string $key): Payout
    {
        $existing = Payout::query()->where('earnings_account_id', $account->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->amount_kobo !== $amount || $existing->payout_account_id !== $bank->id) {
                throw new ApiException('idempotency_key_reused', 409);
            }

            return $existing;
        }

        if (! config('wegodeliva.payouts.enabled')) {
            throw new ApiException('payouts_disabled', 422);
        }
        if ($bank->status !== 'verified') {
            throw new ApiException('bank_account_unverified', 422);
        }
        $minimum = (int) config('wegodeliva.payouts.min_kobo');
        if ($amount < $minimum) {
            throw new ApiException('payout_below_minimum', 422, fields: ['amount_kobo' => ['The minimum payout is '.Money::format($minimum).'.']]);
        }

        return DB::transaction(function () use ($account, $bank, $amount, $by, $key): Payout {
            $payout = new Payout;
            $payout->forceFill([
                'earnings_account_id' => $account->id,
                'payout_account_id' => $bank->id,
                'amount_kobo' => $amount,
                'status' => 'requested',
                'gateway' => $this->gateway(),
                'transfer_reference' => 'WGDP-'.Str::upper((string) Str::ulid()),
                'requested_by' => $by->id,
                'idempotency_key' => $key,
            ])->save();

            $this->earnings->holdForPayout($account, $amount, "payout:{$payout->uuid}:hold");

            return $payout;
        });
    }

    /**
     * Approves a payout: settles what the account owes, then sends the transfer. If the gateway
     * cannot be reached the payout stays approved and approving again retries the transfer.
     */
    public function approve(Payout $payout, User $admin): Payout
    {
        DB::transaction(function () use ($payout, $admin): void {
            /** @var Payout $locked */
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'requested') {
                $this->earnings->settleForPayout($locked->earningsAccount()->firstOrFail(), "payout:{$locked->uuid}:settle");
                $locked->forceFill(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
                $this->audit->record($admin, 'payout.approved', 'payout', $locked->uuid, after: ['amount_kobo' => $locked->amount_kobo]);
            } elseif ($locked->status !== 'approved') {
                throw new ApiException('payout_status_conflict', 409, meta: ['status' => $locked->status]);
            }
            $payout->setRawAttributes($locked->getAttributes(), true);
        });

        try {
            $transferId = $this->gateways->transfers($payout->gateway)->sendTransfer($payout, $payout->payoutAccount()->firstOrFail());
        } catch (GatewayUnavailable $e) {
            Log::error('Payout transfer could not be sent', ['payout' => $payout->uuid, 'error' => $e->getMessage()]);
            $payout->forceFill(['failure_reason' => 'gateway_unavailable'])->save();
            throw new ApiException('gateway_unavailable', 503);
        }

        $payout->forceFill(['status' => 'processing', 'gateway_transfer_id' => $transferId, 'failure_reason' => null])->save();
        event(new PayoutUpdated($payout->id));

        return $payout;
    }

    public function reject(Payout $payout, User $admin, string $reason): Payout
    {
        return DB::transaction(function () use ($payout, $admin, $reason): Payout {
            /** @var Payout $locked */
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') {
                throw new ApiException('payout_status_conflict', 409, meta: ['status' => $locked->status]);
            }
            $this->earnings->releasePayout($locked->earningsAccount()->firstOrFail(), $locked->amount_kobo, "payout:{$locked->uuid}:release");
            $locked->forceFill(['status' => 'rejected', 'note' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $this->audit->record($admin, 'payout.rejected', 'payout', $locked->uuid, after: ['reason' => $reason]);
            event(new PayoutUpdated($locked->id));

            return $payout->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Asks the gateway how the transfer went and pays or releases accordingly. */
    public function sync(Payout $payout): Payout
    {
        if ($payout->status !== 'processing') {
            return $payout;
        }
        $status = $this->gateways->transfers($payout->gateway)->transferStatus($payout->transfer_reference);
        if ($status === 'pending') {
            return $payout;
        }

        return DB::transaction(function () use ($payout, $status): Payout {
            /** @var Payout $locked */
            $locked = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'processing') {
                return $payout->setRawAttributes($locked->getAttributes(), true);
            }
            $account = $locked->earningsAccount()->firstOrFail();

            if ($status === 'success') {
                $this->earnings->completePayout($account, $locked->amount_kobo, "payout:{$locked->uuid}:paid");
                $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            } else {
                // The money stays available; the commission settled at approval stays settled.
                $this->earnings->releasePayout($account, $locked->amount_kobo, "payout:{$locked->uuid}:release");
                $locked->forceFill(['status' => 'failed', 'failure_reason' => 'transfer_failed'])->save();
                $this->audit->record(null, 'payout.transfer_failed', 'payout', $locked->uuid, after: ['amount_kobo' => $locked->amount_kobo]);
            }
            event(new PayoutUpdated($locked->id));

            return $payout->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Checks payouts that have been processing for a while (the webhook may never come). */
    public function syncStale(): int
    {
        $count = 0;
        Payout::query()->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(5))->orderBy('id')
            ->each(function (Payout $payout) use (&$count): void {
                try {
                    if ($this->sync($payout)->status !== 'processing') {
                        $count++;
                    }
                } catch (GatewayUnavailable $e) {
                    Log::warning('Payout status unavailable', ['payout' => $payout->uuid, 'error' => $e->getMessage()]);
                }
            });

        return $count;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function unavailableAs503(callable $call): mixed
    {
        try {
            return $call();
        } catch (GatewayUnavailable) {
            throw new ApiException('gateway_unavailable', 503);
        }
    }
}

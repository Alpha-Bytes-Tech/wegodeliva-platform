<?php

namespace App\Services\Ledger;

use App\Enums\EarningsBucket;
use App\Enums\EarningsKind;
use App\Enums\EarningsOwner;
use App\Exceptions\ApiException;
use App\Models\EarningsAccount;
use App\Models\EarningsEntry;
use App\Models\User;
use App\Services\Pricing\EarningsShare;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way vendor and rider earnings change (spec 05 §5.8, spec 06 §6.5–6.7). Earnings are
 * credited gross at delivery and commission accrues against them; commission, its VAT and a
 * rider's cash owed are settled out of the next payout. Every operation locks the account row,
 * writes one entry per figure it moves, and is idempotent on its reference.
 */
final class EarningsLedger
{
    public function account(EarningsOwner $type, int $ownerId): EarningsAccount
    {
        DB::table('earnings_accounts')->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'owner_type' => $type->value,
            'owner_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return EarningsAccount::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->firstOrFail();
    }

    /** At delivery: credit the gross share and accrue the commission and its VAT. */
    public function recordOrderEarning(EarningsAccount $account, EarningsShare $share, string $reference, ?int $orderId = null): void
    {
        $this->operation($account, $reference, function () use ($share): array {
            return [
                [EarningsKind::OrderEarning, EarningsBucket::Balance, $share->grossKobo],
                [EarningsKind::CommissionAccrued, EarningsBucket::CommissionDue, $share->commissionKobo],
                [EarningsKind::CommissionVatAccrued, EarningsBucket::CommissionVatDue, $share->commissionVatKobo],
            ];
        }, ['order_id' => $orderId]);
    }

    /**
     * Takes back earnings (refund at the vendor's or rider's fault, spec 06 §6.6). The balance
     * may go negative; the debt is netted at the next withdrawal.
     */
    public function reverse(EarningsAccount $account, int $amount, string $reference, string $note, ?int $orderId = null): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, fn (): array => [
            [EarningsKind::Reversal, EarningsBucket::Balance, -$amount],
        ], ['order_id' => $orderId, 'note' => $note]);
    }

    /** An admin's manual credit (positive) or debit (negative) of earnings, with a reason. */
    public function adjust(EarningsAccount $account, User $admin, int $signedAmount, string $reason, string $reference): void
    {
        if ($signedAmount === 0) {
            throw new \InvalidArgumentException('An adjustment needs a non-zero amount.');
        }
        $this->operation($account, $reference, fn (): array => [
            [EarningsKind::Adjustment, EarningsBucket::Balance, $signedAmount],
        ], ['note' => $reason, 'created_by' => $admin->id]);
    }

    /** Pay-on-delivery cash a rider collected and now owes WeGoDeliva. */
    public function collectCash(EarningsAccount $account, int $amount, string $reference, ?int $orderId = null): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, fn (): array => [
            [EarningsKind::CashCollected, EarningsBucket::CashOwed, $amount],
        ], ['order_id' => $orderId]);
    }

    /** Cash a rider paid back to WeGoDeliva (recorded by an admin). */
    public function remitCash(EarningsAccount $account, int $amount, string $reference, User $admin, ?string $note = null): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, function (EarningsAccount $locked) use ($amount): array {
            if ($amount > $locked->cash_owed_kobo) {
                throw new ApiException('remittance_exceeds_cash_owed', 422, meta: ['cash_owed' => Money::of($locked->cash_owed_kobo)]);
            }

            return [[EarningsKind::CashRemitted, EarningsBucket::CashOwed, -$amount]];
        }, ['note' => $note, 'created_by' => $admin->id]);
    }

    /** Reserves earnings for a requested payout; refused above what is available. */
    public function holdForPayout(EarningsAccount $account, int $amount, string $reference): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, function (EarningsAccount $locked) use ($amount): array {
            if ($amount > $locked->availableKobo()) {
                throw new ApiException('insufficient_earnings', 422, meta: ['available' => Money::of($locked->availableKobo())]);
            }

            return [[EarningsKind::PayoutHold, EarningsBucket::Held, $amount]];
        });
    }

    /**
     * At payout approval: settle all accrued commission, its VAT and (riders) the cash owed out
     * of the balance (spec 06 §6.7). Settlement stays even if the payout later fails.
     */
    public function settleForPayout(EarningsAccount $account, string $reference): void
    {
        $this->operation($account, $reference, fn (EarningsAccount $locked): array => [
            [EarningsKind::CommissionSettled, EarningsBucket::CommissionDue, -$locked->commission_due_kobo],
            [EarningsKind::CommissionSettled, EarningsBucket::Balance, -$locked->commission_due_kobo],
            [EarningsKind::CommissionVatSettled, EarningsBucket::CommissionVatDue, -$locked->commission_vat_due_kobo],
            [EarningsKind::CommissionVatSettled, EarningsBucket::Balance, -$locked->commission_vat_due_kobo],
            [EarningsKind::CashOffset, EarningsBucket::CashOwed, -$locked->cash_owed_kobo],
            [EarningsKind::CashOffset, EarningsBucket::Balance, -$locked->cash_owed_kobo],
        ]);
    }

    /** The transfer went through: the held amount leaves the account. */
    public function completePayout(EarningsAccount $account, int $amount, string $reference): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, fn (EarningsAccount $locked): array => $this->releasing($locked, $amount, [
            [EarningsKind::PayoutPaid, EarningsBucket::Held, -$amount],
            [EarningsKind::PayoutPaid, EarningsBucket::Balance, -$amount],
        ]));
    }

    /** The payout was rejected or the transfer failed: the held amount is available again. */
    public function releasePayout(EarningsAccount $account, int $amount, string $reference): void
    {
        $this->positive($amount);
        $this->operation($account, $reference, fn (EarningsAccount $locked): array => $this->releasing($locked, $amount, [
            [EarningsKind::PayoutReleased, EarningsBucket::Held, -$amount],
        ]));
    }

    /**
     * @param  list<array{0: EarningsKind, 1: EarningsBucket, 2: int}>  $postings
     * @return list<array{0: EarningsKind, 1: EarningsBucket, 2: int}>
     */
    private function releasing(EarningsAccount $locked, int $amount, array $postings): array
    {
        if ($amount > $locked->held_kobo) {
            throw new \InvalidArgumentException('Cannot release more than is held.');
        }

        return $postings;
    }

    private function positive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('The amount must be positive.');
        }
    }

    /**
     * Locks the account and applies the postings returned by $build, unless an operation with
     * this reference already ran. Zero postings are skipped.
     *
     * @param  callable(EarningsAccount): list<array{0: EarningsKind, 1: EarningsBucket, 2: int}>  $build
     * @param  array{order_id?: int|null, note?: string|null, created_by?: int|null}  $context
     */
    private function operation(EarningsAccount $account, string $reference, callable $build, array $context = []): void
    {
        DB::transaction(function () use ($account, $reference, $build, $context): void {
            /** @var EarningsAccount $locked */
            $locked = EarningsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($locked->entries()->where('reference', $reference)->exists()) {
                return;
            }

            foreach ($build($locked) as [$kind, $bucket, $amount]) {
                if ($amount === 0) {
                    continue;
                }
                $column = $bucket->column();
                $locked->setAttribute($column, $locked->getAttribute($column) + $amount);

                $entry = new EarningsEntry;
                $entry->forceFill([
                    'earnings_account_id' => $locked->id,
                    'kind' => $kind,
                    'bucket' => $bucket,
                    'amount_kobo' => $amount,
                    'bucket_after_kobo' => $locked->getAttribute($column),
                    'reference' => $reference,
                    'order_id' => $context['order_id'] ?? null,
                    'note' => $context['note'] ?? null,
                    'created_by' => $context['created_by'] ?? null,
                ])->save();
            }

            $locked->save();
            $account->setRawAttributes($locked->getAttributes(), true);
        });
    }
}

<?php

namespace App\Services\Ledger;

use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way a wallet balance changes (spec 06 §6.4). Each posting locks the wallet row,
 * writes a ledger entry with the balance after it, and is idempotent on its reference: posting
 * the same reference again returns the first entry and changes nothing.
 */
final class WalletLedger
{
    /** The user's wallet, created on first use. */
    public function wallet(User $user): Wallet
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        if ($wallet !== null) {
            return $wallet;
        }

        // Two first requests at once: the unique key lets one insert win; the other reads it.
        DB::table('wallets')->insertOrIgnore(['uuid' => (string) Str::orderedUuid(), 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return Wallet::query()->where('user_id', $user->id)->firstOrFail();
    }

    public function limits(Wallet $wallet): WalletLimits
    {
        return WalletLimits::forTier($wallet->kyc_tier);
    }

    /** Top-ups credited today (platform time zone), for the daily limit. */
    public function topUpsToday(Wallet $wallet, ?Carbon $now = null): int
    {
        $start = ($now ?? now())->copy()->setTimezone((string) config('wegodeliva.timezone'))->startOfDay();

        return (int) $wallet->transactions()
            ->where('kind', WalletKind::TopUp->value)
            ->where('created_at', '>=', $start->utc())
            ->sum('amount_kobo');
    }

    /**
     * Credits a top-up, refund or referral reward. Refused when it would take the wallet above
     * its tier's balance cap (`wallet_balance_cap`).
     *
     * @param  array{order_id?: int|null, gateway?: string|null, note?: string|null}  $context
     */
    public function credit(User $user, int $amount, WalletKind $kind, string $reference, array $context = []): WalletTransaction
    {
        if (! $kind->isCredit() || $amount <= 0) {
            throw new \InvalidArgumentException('A credit needs a credit kind and a positive amount.');
        }

        return $this->post($user, $amount, $kind, $reference, $context, function (Wallet $wallet) use ($amount): void {
            $cap = $this->limits($wallet)->maxBalanceKobo;
            if ($cap !== null && $wallet->balance_kobo + $amount > $cap) {
                throw new ApiException('wallet_balance_cap', 422, meta: [
                    'max_balance' => Money::of($cap),
                    'room' => Money::of(max(0, $cap - $wallet->balance_kobo)),
                ]);
            }
        });
    }

    /**
     * Debits an order payment or a reversal. Refused when the balance does not cover it
     * (`insufficient_wallet_balance`).
     *
     * @param  array{order_id?: int|null, gateway?: string|null, note?: string|null}  $context
     */
    public function debit(User $user, int $amount, WalletKind $kind, string $reference, array $context = []): WalletTransaction
    {
        if (! in_array($kind, [WalletKind::OrderPayment, WalletKind::Reversal], true) || $amount <= 0) {
            throw new \InvalidArgumentException('A debit needs a debit kind and a positive amount.');
        }

        return $this->post($user, -$amount, $kind, $reference, $context, function (Wallet $wallet) use ($amount): void {
            if ($wallet->balance_kobo < $amount) {
                throw new ApiException('insufficient_wallet_balance', 422, meta: ['balance' => Money::of(max(0, $wallet->balance_kobo))]);
            }
        });
    }

    /**
     * An admin's manual credit (positive) or debit (negative), with a reason. The only posting
     * that may take a wallet below zero or above its cap.
     */
    public function adjust(User $user, User $admin, int $signedAmount, string $reason, string $reference): WalletTransaction
    {
        return $this->post($user, $signedAmount, WalletKind::AdminAdjustment, $reference, ['note' => $reason, 'created_by' => $admin->id]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  (callable(Wallet): void)|null  $check  runs under the lock, before the posting
     */
    private function post(User $user, int $signedAmount, WalletKind $kind, string $reference, array $context, ?callable $check = null): WalletTransaction
    {
        if ($signedAmount === 0) {
            throw new \InvalidArgumentException('A posting needs a non-zero amount.');
        }
        $walletId = $this->wallet($user)->id;

        return DB::transaction(function () use ($walletId, $signedAmount, $kind, $reference, $context, $check): WalletTransaction {
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();

            $existing = WalletTransaction::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                if ($existing->wallet_id !== $wallet->id || $existing->kind !== $kind || $existing->signedAmount() !== $signedAmount) {
                    throw new LedgerConflict("Reference {$reference} was already used for a different posting.");
                }

                return $existing;
            }

            if ($check !== null) {
                $check($wallet);
            }

            $wallet->balance_kobo += $signedAmount;
            $wallet->save();

            $entry = new WalletTransaction;
            $entry->forceFill([
                'wallet_id' => $wallet->id,
                'direction' => $signedAmount > 0 ? 'credit' : 'debit',
                'amount_kobo' => abs($signedAmount),
                'balance_after_kobo' => $wallet->balance_kobo,
                'kind' => $kind,
                'reference' => $reference,
                'order_id' => $context['order_id'] ?? null,
                'gateway' => $context['gateway'] ?? null,
                'note' => $context['note'] ?? null,
                'created_by' => $context['created_by'] ?? null,
            ])->save();

            return $entry;
        });
    }
}

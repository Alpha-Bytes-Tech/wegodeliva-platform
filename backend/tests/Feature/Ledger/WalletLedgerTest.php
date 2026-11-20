<?php

use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Ledger\LedgerConflict;
use App\Services\Ledger\WalletLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(WalletLedger::class);
    $this->user = makeUser();
});

function walletError(Closure $call): ApiException
{
    try {
        $call();
    } catch (ApiException $e) {
        return $e;
    }
    throw new RuntimeException('Expected an ApiException.');
}

it('creates one wallet per user on first use, at tier 1 with a zero balance', function () {
    $first = $this->ledger->wallet($this->user);
    $again = $this->ledger->wallet($this->user);

    expect($again->id)->toBe($first->id)
        ->and($first->balance_kobo)->toBe(0)
        ->and($first->currency)->toBe('NGN')
        ->and($first->kyc_tier)->toBe(1)
        ->and(Wallet::query()->count())->toBe(1)
        ->and($this->user->wallet()->first()?->id)->toBe($first->id);
});

it('credits and debits through the ledger, recording the balance after each entry', function () {
    $this->ledger->credit($this->user, 500000, WalletKind::TopUp, 'topup:1', ['gateway' => 'paystack']);
    $this->ledger->credit($this->user, 25000, WalletKind::Refund, 'refund:1', ['order_id' => 77, 'note' => 'Missing item']);
    $debit = $this->ledger->debit($this->user, 300000, WalletKind::OrderPayment, 'order:1', ['order_id' => 78]);

    expect($debit->direction)->toBe('debit')
        ->and($debit->amount_kobo)->toBe(300000)
        ->and($debit->balance_after_kobo)->toBe(225000)
        ->and($debit->signedAmount())->toBe(-300000)
        ->and($this->ledger->wallet($this->user)->balance_kobo)->toBe(225000);

    $entries = WalletTransaction::query()->orderBy('id')->get();
    expect($entries->map->balance_after_kobo->all())->toBe([500000, 525000, 225000])
        ->and($entries[0]->gateway)->toBe('paystack')
        ->and($entries[1]->order_id)->toBe(77)
        ->and($entries[1]->note)->toBe('Missing item')
        ->and($entries[1]->signedAmount())->toBe(25000)
        ->and($entries->sum(fn (WalletTransaction $t) => $t->signedAmount()))->toBe(225000);
});

it('applies a reference only once', function () {
    $first = $this->ledger->credit($this->user, 10000, WalletKind::ReferralReward, 'referral:9');
    $again = $this->ledger->credit($this->user, 10000, WalletKind::ReferralReward, 'referral:9');

    expect($again->id)->toBe($first->id)
        ->and($this->ledger->wallet($this->user)->balance_kobo)->toBe(10000)
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('refuses to reuse a reference for a different posting', function (Closure $reuse) {
    $this->ledger->credit($this->user, 10000, WalletKind::TopUp, 'ref:1');

    $reuse($this->ledger, $this->user);
})->with([
    'different amount' => [fn ($ledger, $user) => $ledger->credit($user, 10001, WalletKind::TopUp, 'ref:1')],
    'different kind' => [fn ($ledger, $user) => $ledger->credit($user, 10000, WalletKind::Refund, 'ref:1')],
    'different wallet' => [fn ($ledger, $user) => $ledger->credit(makeUser(), 10000, WalletKind::TopUp, 'ref:1')],
])->throws(LedgerConflict::class);

it('never lets a debit take the wallet below zero', function () {
    $this->ledger->credit($this->user, 5000, WalletKind::TopUp, 't1');

    $error = walletError(fn () => $this->ledger->debit($this->user, 5001, WalletKind::OrderPayment, 'o1'));

    expect($error->errorCode)->toBe('insufficient_wallet_balance')
        ->and($error->meta['balance']['amount_kobo'])->toBe(5000)
        ->and($this->ledger->wallet($this->user)->balance_kobo)->toBe(5000)
        ->and(WalletTransaction::query()->count())->toBe(1);
    expect($this->ledger->debit($this->user, 5000, WalletKind::Reversal, 'r1')->balance_after_kobo)->toBe(0);
});

it('refuses credits above the tier balance cap, but allows them at a higher tier', function () {
    $this->ledger->credit($this->user, 29_000_000, WalletKind::TopUp, 't1');

    $error = walletError(fn () => $this->ledger->credit($this->user, 1_000_001, WalletKind::Refund, 'r1'));
    expect($error->errorCode)->toBe('wallet_balance_cap')
        ->and($error->meta['max_balance']['amount_kobo'])->toBe(30_000_000)
        ->and($error->meta['room']['amount_kobo'])->toBe(1_000_000);

    $this->ledger->credit($this->user, 1_000_000, WalletKind::Refund, 'r2');
    Wallet::query()->update(['kyc_tier' => 3]);
    $this->ledger->credit($this->user, 900_000_000, WalletKind::TopUp, 't2');

    expect($this->ledger->wallet($this->user)->balance_kobo)->toBe(930_000_000);
});

it('lets an admin adjust in either direction, below zero and above the cap, with a reason', function () {
    $admin = makeUser(RoleName::Admin);

    $this->ledger->adjust($this->user, $admin, -2500, 'Goodwill reversal', 'adj:1');
    $credit = $this->ledger->adjust($this->user, $admin, 40_000_000, 'Migration credit', 'adj:2');

    expect($credit->kind)->toBe(WalletKind::AdminAdjustment)
        ->and($credit->kind->label())->toBe('Adjustment')
        ->and($credit->created_by)->toBe($admin->id)
        ->and($credit->note)->toBe('Migration credit')
        ->and($this->ledger->wallet($this->user)->balance_kobo)->toBe(39_997_500)
        ->and(WalletTransaction::query()->where('reference', 'adj:1')->sole()->balance_after_kobo)->toBe(-2500);
});

it('rejects postings with the wrong kind or amount', function (Closure $call) {
    $call($this->ledger, $this->user);
})->with([
    'debit kind as credit' => [fn ($l, $u) => $l->credit($u, 100, WalletKind::OrderPayment, 'x')],
    'adjustment as credit' => [fn ($l, $u) => $l->credit($u, 100, WalletKind::AdminAdjustment, 'x')],
    'zero credit' => [fn ($l, $u) => $l->credit($u, 0, WalletKind::TopUp, 'x')],
    'credit kind as debit' => [fn ($l, $u) => $l->debit($u, 100, WalletKind::TopUp, 'x')],
    'negative debit' => [fn ($l, $u) => $l->debit($u, -100, WalletKind::OrderPayment, 'x')],
    'zero adjustment' => [fn ($l, $u) => $l->adjust($u, $u, 0, 'Nothing', 'x')],
])->throws(InvalidArgumentException::class);

it('counts today\'s top-ups in Lagos time for the daily limit', function () {
    Carbon::setTestNow('2026-12-01 22:30:00'); // 23:30 in Lagos
    $this->ledger->credit($this->user, 10000, WalletKind::TopUp, 't1');
    $this->ledger->credit($this->user, 99999, WalletKind::Refund, 'r1');
    Carbon::setTestNow('2026-12-01 23:30:00'); // 00:30 the next day in Lagos
    $this->ledger->credit($this->user, 20000, WalletKind::TopUp, 't2');

    $wallet = $this->ledger->wallet($this->user);
    expect($this->ledger->topUpsToday($wallet))->toBe(20000)
        ->and($this->ledger->topUpsToday($wallet, Carbon::parse('2026-12-01 22:45:00')))->toBe(30000);

    $limits = $this->ledger->limits($wallet);
    expect([$limits->tier, $limits->minTopUpKobo, $limits->dailyTopUpKobo, $limits->maxBalanceKobo])->toBe([1, 10000, 5_000_000, 30_000_000]);
    Carbon::setTestNow();
});

it('keeps the ledger append-only in the database', function (string $sql) {
    $this->ledger->credit($this->user, 10000, WalletKind::TopUp, 't1');

    expect(fn () => DB::statement($sql))->toThrow(QueryException::class, 'wallet_transactions is append-only');
})->with([
    'update' => ['UPDATE wallet_transactions SET amount_kobo = 1'],
    'delete' => ['DELETE FROM wallet_transactions'],
]);

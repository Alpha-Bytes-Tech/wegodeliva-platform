<?php

use App\Enums\EarningsBucket;
use App\Enums\EarningsKind;
use App\Enums\EarningsOwner;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\EarningsAccount;
use App\Models\EarningsEntry;
use App\Services\Ledger\EarningsLedger;
use App\Services\Pricing\EarningsShare;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(EarningsLedger::class);
    $this->vendor = $this->ledger->account(EarningsOwner::Vendor, 41);
    $this->rider = $this->ledger->account(EarningsOwner::Rider, 41);
    $this->admin = makeUser(RoleName::Admin);
});

/** The spec 05 worked example: vendor gross ₦4,837.50, commission ₦225, VAT on it ₦16.88. */
function vendorShare(): EarningsShare
{
    return new EarningsShare(483750, 450000, 500, 22500, 1688);
}

/** @return array<string, int> */
function figures(EarningsAccount $account): array
{
    $account->refresh();

    return [
        'balance' => $account->balance_kobo,
        'commission_due' => $account->commission_due_kobo,
        'commission_vat_due' => $account->commission_vat_due_kobo,
        'cash_owed' => $account->cash_owed_kobo,
        'held' => $account->held_kobo,
        'available' => $account->availableKobo(),
    ];
}

/** Every figure equals the sum of its ledger entries. */
function expectLedgerMatches(EarningsAccount $account): void
{
    $account->refresh();
    foreach (EarningsBucket::cases() as $bucket) {
        $sum = (int) EarningsEntry::query()->where('earnings_account_id', $account->id)->where('bucket', $bucket->value)->sum('amount_kobo');
        expect($sum)->toBe($account->getAttribute($bucket->column()));
    }
}

it('keeps one account per owner, separate for vendors and riders', function () {
    expect($this->ledger->account(EarningsOwner::Vendor, 41)->id)->toBe($this->vendor->id)
        ->and($this->rider->id)->not->toBe($this->vendor->id)
        ->and($this->vendor->owner_type)->toBe(EarningsOwner::Vendor)
        ->and(EarningsAccount::query()->count())->toBe(2);
});

it('credits gross earnings at delivery and accrues commission against them, once per order', function () {
    $this->ledger->recordOrderEarning($this->vendor, vendorShare(), 'order:9:vendor', orderId: 9);
    $this->ledger->recordOrderEarning($this->vendor, vendorShare(), 'order:9:vendor', orderId: 9);

    // Available ₦4,595.62, as in the spec.
    expect(figures($this->vendor))->toBe(['balance' => 483750, 'commission_due' => 22500, 'commission_vat_due' => 1688, 'cash_owed' => 0, 'held' => 0, 'available' => 459562])
        ->and(EarningsEntry::query()->count())->toBe(3)
        ->and(EarningsEntry::query()->pluck('kind')->map->value->all())->toBe(['order_earning', 'commission_accrued', 'commission_vat_accrued'])
        ->and(EarningsEntry::query()->where('order_id', 9)->count())->toBe(3);
    expectLedgerMatches($this->vendor);
});

it('skips zero postings, e.g. a commission-free share', function () {
    $this->ledger->recordOrderEarning($this->rider, new EarningsShare(20000, 0, 300, 0, 0), 'order:1:rider');

    expect(EarningsEntry::query()->count())->toBe(1)->and(figures($this->rider)['available'])->toBe(20000);
});

it('runs a payout: hold, settle commission and cash owed, then pay', function () {
    $this->ledger->recordOrderEarning($this->rider, new EarningsShare(120000, 100000, 300, 3000, 225), 'order:1:rider', 1);
    $this->ledger->collectCash($this->rider, 50000, 'order:2:cash', 2);
    expect(figures($this->rider)['available'])->toBe(120000 - 3000 - 225 - 50000);

    $this->ledger->holdForPayout($this->rider, 60000, 'payout:1:hold');
    expect(figures($this->rider))->toMatchArray(['held' => 60000, 'available' => 6775]);

    $this->ledger->settleForPayout($this->rider, 'payout:1:settle');
    expect(figures($this->rider))->toBe(['balance' => 120000 - 3225 - 50000, 'commission_due' => 0, 'commission_vat_due' => 0, 'cash_owed' => 0, 'held' => 60000, 'available' => 6775]);

    $this->ledger->completePayout($this->rider, 60000, 'payout:1:paid');
    $this->ledger->completePayout($this->rider, 60000, 'payout:1:paid');
    expect(figures($this->rider))->toBe(['balance' => 6775, 'commission_due' => 0, 'commission_vat_due' => 0, 'cash_owed' => 0, 'held' => 0, 'available' => 6775]);
    expectLedgerMatches($this->rider);
});

it('releases a held payout when it is rejected or the transfer fails', function () {
    $this->ledger->recordOrderEarning($this->vendor, vendorShare(), 'o1');
    $this->ledger->holdForPayout($this->vendor, 400000, 'p1:hold');
    $this->ledger->releasePayout($this->vendor, 400000, 'p1:release');

    expect(figures($this->vendor))->toMatchArray(['held' => 0, 'available' => 459562, 'balance' => 483750]);
    expect(EarningsEntry::query()->where('reference', 'p1:release')->sole()->kind)->toBe(EarningsKind::PayoutReleased);
});

it('refuses to hold more than is available or release more than is held', function () {
    $this->ledger->recordOrderEarning($this->vendor, vendorShare(), 'o1');

    try {
        $this->ledger->holdForPayout($this->vendor, 459563, 'p1');
        $this->fail('Expected the hold to be refused.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('insufficient_earnings')->and($e->meta['available']['amount_kobo'])->toBe(459562);
    }

    $this->ledger->holdForPayout($this->vendor, 1000, 'p2');
    expect(fn () => $this->ledger->releasePayout($this->vendor, 1001, 'p2:release'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->ledger->completePayout($this->vendor, 1001, 'p2:paid'))->toThrow(InvalidArgumentException::class)
        ->and(figures($this->vendor)['held'])->toBe(1000);
});

it('lets reversals take the balance negative and nets the debt from availability', function () {
    $this->ledger->recordOrderEarning($this->rider, new EarningsShare(1000, 1000, 300, 30, 2), 'o1');
    $this->ledger->reverse($this->rider, 5000, 'o1:reversal', 'Item lost in transit', 1);

    $entry = EarningsEntry::query()->where('kind', 'reversal')->sole();
    expect(figures($this->rider))->toMatchArray(['balance' => -4000, 'available' => 0])
        ->and($entry->note)->toBe('Item lost in transit')
        ->and($entry->bucket_after_kobo)->toBe(-4000);
});

it('records cash remittances, never more than is owed', function () {
    $this->ledger->collectCash($this->rider, 15000, 'cash:1');
    $this->ledger->remitCash($this->rider, 10000, 'remit:1', $this->admin, 'Transfer to collection account');

    expect(figures($this->rider)['cash_owed'])->toBe(5000)
        ->and(EarningsEntry::query()->where('reference', 'remit:1')->sole()->created_by)->toBe($this->admin->id);

    try {
        $this->ledger->remitCash($this->rider, 5001, 'remit:2', $this->admin);
        $this->fail('Expected the remittance to be refused.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('remittance_exceeds_cash_owed')->and($e->meta['cash_owed']['amount_kobo'])->toBe(5000);
    }
});

it('lets an admin adjust earnings either way, with a reason', function () {
    $this->ledger->adjust($this->vendor, $this->admin, 5000, 'Compensation', 'adj:1');
    $this->ledger->adjust($this->vendor, $this->admin, -2000, 'Correction', 'adj:2');

    expect(figures($this->vendor)['balance'])->toBe(3000)
        ->and(EarningsEntry::query()->where('reference', 'adj:2')->sole()->note)->toBe('Correction');
    expect(fn () => $this->ledger->adjust($this->vendor, $this->admin, 0, 'Nothing', 'adj:3'))->toThrow(InvalidArgumentException::class);
});

it('rejects non-positive amounts', function (string $method) {
    $args = match ($method) {
        'remitCash' => [$this->rider, 0, 'x', $this->admin],
        'reverse' => [$this->rider, 0, 'x', 'note'],
        default => [$this->rider, 0, 'x'],
    };
    $this->ledger->{$method}(...$args);
})->with(['reverse', 'collectCash', 'remitCash', 'holdForPayout', 'completePayout', 'releasePayout'])->throws(InvalidArgumentException::class);

it('keeps the ledger append-only in the database', function () {
    $this->ledger->collectCash($this->rider, 100, 'cash:1');

    expect(fn () => DB::statement('DELETE FROM earnings_entries'))->toThrow(QueryException::class, 'earnings_entries is append-only');
});

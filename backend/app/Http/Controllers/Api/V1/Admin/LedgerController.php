<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EarningsKind;
use App\Enums\EarningsOwner;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CashRemittanceRequest;
use App\Http\Requests\Admin\LedgerAdjustmentRequest;
use App\Http\Resources\EarningsAccountResource;
use App\Http\Resources\EarningsEntryResource;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\EarningsAccount;
use App\Models\EarningsEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\AuditLogger;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\LedgerConflict;
use App\Services\Ledger\WalletLedger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Wallets and earnings for finance staff (spec 06 §6.4–6.5, spec 12): view balances and
 * ledgers, adjust with a reason, and record riders' cash remittances. Every change is audited
 * and keyed by the client's idempotency key, so a retried request is applied once.
 */
class LedgerController extends Controller
{
    public function __construct(
        private readonly WalletLedger $wallets,
        private readonly EarningsLedger $earnings,
        private readonly AuditLogger $audit,
    ) {}

    public function wallet(User $user): WalletResource
    {
        Gate::authorize('manage-money');

        return new WalletResource($this->wallets->wallet($user));
    }

    public function walletTransactions(Request $request, User $user): AnonymousResourceCollection
    {
        Gate::authorize('manage-money');

        $page = $this->wallets->wallet($user)->transactions()->orderByDesc('id')->cursorPaginate($this->perPage($request));
        $page->getCollection()->transform(function (WalletTransaction $t) {
            $resource = new WalletTransactionResource($t);
            $resource->forAdmin = true;

            return $resource;
        });

        return WalletTransactionResource::collection($page);
    }

    public function adjustWallet(LedgerAdjustmentRequest $request, User $user): JsonResponse
    {
        Gate::authorize('manage-money');
        /** @var User $admin */
        $admin = $request->user();
        $amount = (int) $request->validated('amount_kobo');
        $reason = (string) $request->validated('reason');

        try {
            $entry = $this->wallets->adjust($user, $admin, $amount, $reason, 'admin-adjust:'.$request->validated('idempotency_key'));
        } catch (LedgerConflict) {
            throw new ApiException('conflict', 409);
        }

        if ($entry->wasRecentlyCreated) {
            $this->audit->record($admin, 'wallet.adjusted', 'user', $user->uuid, after: ['amount_kobo' => $amount, 'reason' => $reason, 'balance_after_kobo' => $entry->balance_after_kobo]);
        }

        $resource = new WalletTransactionResource($entry);
        $resource->forAdmin = true;

        return $resource->response()->setStatusCode($entry->wasRecentlyCreated ? 201 : 200);
    }

    public function vendorEarnings(Vendor $vendor): EarningsAccountResource
    {
        Gate::authorize('manage-money');

        return new EarningsAccountResource($this->earnings->account(EarningsOwner::Vendor, $vendor->id));
    }

    public function vendorEntries(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        Gate::authorize('manage-money');

        return $this->entries($request, $this->earnings->account(EarningsOwner::Vendor, $vendor->id));
    }

    public function adjustVendor(LedgerAdjustmentRequest $request, Vendor $vendor): EarningsAccountResource
    {
        Gate::authorize('manage-money');

        return $this->adjustEarnings($request, $this->earnings->account(EarningsOwner::Vendor, $vendor->id), 'vendor', $vendor->uuid);
    }

    public function riderEarnings(User $rider): EarningsAccountResource
    {
        Gate::authorize('manage-money');

        return new EarningsAccountResource($this->riderAccount($rider));
    }

    public function riderEntries(Request $request, User $rider): AnonymousResourceCollection
    {
        Gate::authorize('manage-money');

        return $this->entries($request, $this->riderAccount($rider));
    }

    public function adjustRider(LedgerAdjustmentRequest $request, User $rider): EarningsAccountResource
    {
        Gate::authorize('manage-money');

        return $this->adjustEarnings($request, $this->riderAccount($rider), 'rider', $rider->uuid);
    }

    public function remitCash(CashRemittanceRequest $request, User $rider): EarningsAccountResource
    {
        Gate::authorize('manage-money');
        /** @var User $admin */
        $admin = $request->user();
        $account = $this->riderAccount($rider);
        $amount = (int) $request->validated('amount_kobo');
        $reference = 'admin-remit:'.$request->validated('idempotency_key');

        if (! $this->alreadyPosted($account, $reference, EarningsKind::CashRemitted, -$amount)) {
            $this->earnings->remitCash($account, $amount, $reference, $admin, $request->validated('note'));
            $this->audit->record($admin, 'rider.cash_remitted', 'rider', $rider->uuid, after: ['amount_kobo' => $amount, 'cash_owed_kobo' => $account->cash_owed_kobo]);
        }

        return new EarningsAccountResource($account->refresh());
    }

    private function adjustEarnings(LedgerAdjustmentRequest $request, EarningsAccount $account, string $subjectType, string $subjectId): EarningsAccountResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $amount = (int) $request->validated('amount_kobo');
        $reason = (string) $request->validated('reason');
        $reference = 'admin-adjust:'.$request->validated('idempotency_key');

        if (! $this->alreadyPosted($account, $reference, EarningsKind::Adjustment, $amount)) {
            $this->earnings->adjust($account, $admin, $amount, $reason, $reference);
            $this->audit->record($admin, 'earnings.adjusted', $subjectType, $subjectId, after: ['amount_kobo' => $amount, 'reason' => $reason, 'balance_kobo' => $account->balance_kobo]);
        }

        return new EarningsAccountResource($account->refresh());
    }

    /** True when this key was already applied with the same amount; 409 if with another. */
    private function alreadyPosted(EarningsAccount $account, string $reference, EarningsKind $kind, int $amount): bool
    {
        $existing = EarningsEntry::query()->where('reference', $reference)->first();
        if ($existing === null) {
            return false;
        }
        if ($existing->earnings_account_id !== $account->id || $existing->kind !== $kind || $existing->amount_kobo !== $amount) {
            throw new ApiException('conflict', 409);
        }

        return true;
    }

    private function riderAccount(User $rider): EarningsAccount
    {
        if (! $rider->hasRole(RoleName::Rider)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $this->earnings->account(EarningsOwner::Rider, $rider->id);
    }

    private function entries(Request $request, EarningsAccount $account): AnonymousResourceCollection
    {
        return EarningsEntryResource::collection($account->entries()->orderByDesc('id')->cursorPaginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']])['per_page'] ?? 25);
    }
}

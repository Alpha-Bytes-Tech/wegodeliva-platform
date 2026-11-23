<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\Payouts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Payout review for finance staff (spec 12 "Payouts"). Every decision is audited. */
class PayoutController extends Controller
{
    public function __construct(private readonly Payouts $payouts, private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-money');
        $status = $request->validate(['status' => ['nullable', 'in:requested,approved,processing,paid,rejected,failed']])['status'] ?? null;

        return PayoutResource::collection(
            Payout::query()->with('payoutAccount')->when($status, fn (Builder $q, string $s) => $q->where('status', $s))->orderByDesc('id')->cursorPaginate(25)
        );
    }

    public function approve(Request $request, Payout $payout): PayoutResource
    {
        Gate::authorize('manage-money');

        return new PayoutResource($this->payouts->approve($payout, $this->admin($request))->load('payoutAccount'));
    }

    public function reject(Request $request, Payout $payout): PayoutResource
    {
        Gate::authorize('manage-money');
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return new PayoutResource($this->payouts->reject($payout, $this->admin($request), $reason)->load('payoutAccount'));
    }

    /** Asks the gateway for the transfer's outcome now, instead of waiting for the webhook. */
    public function sync(Payout $payout): PayoutResource
    {
        Gate::authorize('manage-money');
        try {
            $payout = $this->payouts->sync($payout);
        } catch (GatewayUnavailable) {
            throw new ApiException('gateway_unavailable', 503);
        }

        return new PayoutResource($payout->load('payoutAccount'));
    }

    /** Accepts a bank account whose name did not match automatically. */
    public function approveAccount(Request $request, PayoutAccount $account): PayoutAccountResource
    {
        Gate::authorize('manage-money');
        $account->forceFill(['status' => 'verified'])->save();
        $this->audit->record($this->admin($request), 'payout_account.approved', 'payout_account', $account->uuid, after: ['account_name' => $account->account_name, 'bank' => $account->bank_name]);

        return new PayoutAccountResource($account);
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}

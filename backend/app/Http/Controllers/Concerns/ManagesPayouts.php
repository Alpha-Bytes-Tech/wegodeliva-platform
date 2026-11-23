<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\EarningsOwner;
use App\Exceptions\ApiException;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\EarningsAccount;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Ledger\EarningsLedger;
use App\Services\Payments\Payouts;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Bank accounts and payout requests for one earnings owner (a vendor or a rider). */
trait ManagesPayouts
{
    protected function listAccounts(EarningsOwner $type, int $ownerId): AnonymousResourceCollection
    {
        return PayoutAccountResource::collection(PayoutAccount::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->orderByDesc('is_default')->orderBy('id')->get());
    }

    /** @param  list<string>  $names */
    protected function addAccount(Request $request, EarningsOwner $type, int $ownerId, array $names): JsonResponse
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:12'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ]);

        return (new PayoutAccountResource(app(Payouts::class)->addAccount($type, $ownerId, $data['bank_code'], $data['account_number'], $names)))
            ->response()->setStatusCode(201);
    }

    protected function removeAccount(EarningsOwner $type, int $ownerId, string $uuid): JsonResponse
    {
        $account = $this->ownAccount($type, $ownerId, $uuid);
        if (Payout::query()->where('payout_account_id', $account->id)->whereIn('status', ['requested', 'approved', 'processing'])->exists()) {
            throw new ApiException('bank_account_in_use', 409);
        }
        $account->delete();

        return response()->json(null, 204);
    }

    protected function listPayouts(EarningsOwner $type, int $ownerId): AnonymousResourceCollection
    {
        $account = app(EarningsLedger::class)->account($type, $ownerId);

        return PayoutResource::collection(Payout::query()->where('earnings_account_id', $account->id)->with('payoutAccount')->orderByDesc('id')->cursorPaginate(25));
    }

    protected function requestPayout(Request $request, EarningsOwner $type, int $ownerId): JsonResponse
    {
        $data = $request->validate([
            'amount_kobo' => ['required', 'integer', 'min:1'],
            'payout_account_id' => ['required', 'uuid'],
        ]);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100 || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new ApiException('validation_failed', 400, fields: ['idempotency_key' => ['Send an Idempotency-Key header (letters, digits, - or _, up to 100).']]);
        }

        /** @var EarningsAccount $account */
        $account = app(EarningsLedger::class)->account($type, $ownerId);
        /** @var User $user */
        $user = $request->user();
        $payout = app(Payouts::class)->request($account, $this->ownAccount($type, $ownerId, $data['payout_account_id']), (int) $data['amount_kobo'], $user, $key);

        return (new PayoutResource($payout->load('payoutAccount')))->response()->setStatusCode($payout->wasRecentlyCreated ? 201 : 200);
    }

    private function ownAccount(EarningsOwner $type, int $ownerId, string $uuid): PayoutAccount
    {
        return PayoutAccount::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->where('uuid', $uuid)->first()
            ?? throw (new ModelNotFoundException)->setModel(PayoutAccount::class);
    }
}

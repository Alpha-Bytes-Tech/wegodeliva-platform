<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\EarningsOwner;
use App\Http\Controllers\Concerns\ManagesPayouts;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\Payouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The rider's bank accounts and payouts (spec 06 §6.7). */
class PayoutController extends Controller
{
    use ManagesPayouts;

    public function banks(Payouts $payouts): JsonResponse
    {
        return response()->json(['data' => $payouts->banks()]);
    }

    public function accounts(Request $request): AnonymousResourceCollection
    {
        return $this->listAccounts(EarningsOwner::Rider, $this->rider($request)->id);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $rider = $this->rider($request);

        return $this->addAccount($request, EarningsOwner::Rider, $rider->id, [$rider->name]);
    }

    public function destroyAccount(Request $request, string $account): JsonResponse
    {
        return $this->removeAccount(EarningsOwner::Rider, $this->rider($request)->id, $account);
    }

    public function payouts(Request $request): AnonymousResourceCollection
    {
        return $this->listPayouts(EarningsOwner::Rider, $this->rider($request)->id);
    }

    public function requestPayoutFor(Request $request): JsonResponse
    {
        return $this->requestPayout($request, EarningsOwner::Rider, $this->rider($request)->id);
    }

    private function rider(Request $request): User
    {
        /** @var User $rider */
        $rider = $request->user();

        return $rider;
    }
}

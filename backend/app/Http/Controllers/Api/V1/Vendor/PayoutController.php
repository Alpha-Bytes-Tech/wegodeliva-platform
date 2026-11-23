<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\EarningsOwner;
use App\Enums\VendorMemberRole;
use App\Http\Controllers\Concerns\ManagesPayouts;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Payments\Payouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The store's bank accounts and payouts. Owner only (spec 02 §2.2 "request payout (owner only)"). */
class PayoutController extends VendorController
{
    use ManagesPayouts;

    public function banks(Request $request, Vendor $vendor, Payouts $payouts): JsonResponse
    {
        $this->owner($request, $vendor);

        return response()->json(['data' => $payouts->banks()]);
    }

    public function accounts(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->owner($request, $vendor);

        return $this->listAccounts(EarningsOwner::Vendor, $vendor->id);
    }

    public function storeAccount(Request $request, Vendor $vendor): JsonResponse
    {
        $this->owner($request, $vendor);
        $names = $vendor->members()->wherePivot('role', VendorMemberRole::Owner->value)->get()->map(fn (User $u) => $u->name)->push($vendor->name)->all();

        return $this->addAccount($request, EarningsOwner::Vendor, $vendor->id, array_values($names));
    }

    public function destroyAccount(Request $request, Vendor $vendor, string $account): JsonResponse
    {
        $this->owner($request, $vendor);

        return $this->removeAccount(EarningsOwner::Vendor, $vendor->id, $account);
    }

    public function payouts(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->owner($request, $vendor);

        return $this->listPayouts(EarningsOwner::Vendor, $vendor->id);
    }

    public function requestPayoutFor(Request $request, Vendor $vendor): JsonResponse
    {
        $this->owner($request, $vendor);

        return $this->requestPayout($request, EarningsOwner::Vendor, $vendor->id);
    }
}

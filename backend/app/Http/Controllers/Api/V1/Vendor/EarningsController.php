<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\EarningsOwner;
use App\Http\Resources\EarningsAccountResource;
use App\Http\Resources\EarningsEntryResource;
use App\Models\Vendor;
use App\Services\Ledger\EarningsLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** A store's earnings and ledger, for its owner and staff (spec 02 §2.2, spec 06 §6.7). */
class EarningsController extends VendorController
{
    public function __construct(private readonly EarningsLedger $ledger) {}

    public function show(Request $request, Vendor $vendor): EarningsAccountResource
    {
        $this->member($request, $vendor);

        return new EarningsAccountResource($this->ledger->account(EarningsOwner::Vendor, $vendor->id));
    }

    public function entries(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']])['per_page'] ?? 25;

        return EarningsEntryResource::collection(
            $this->ledger->account(EarningsOwner::Vendor, $vendor->id)->entries()->orderByDesc('id')->cursorPaginate((int) $perPage)
        );
    }
}

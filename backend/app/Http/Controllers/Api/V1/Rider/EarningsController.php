<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\EarningsOwner;
use App\Http\Controllers\Controller;
use App\Http\Resources\EarningsAccountResource;
use App\Http\Resources\EarningsEntryResource;
use App\Models\User;
use App\Services\Ledger\EarningsLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The rider's own earnings, cash owed and ledger (spec 06 §6.5, §6.7). */
class EarningsController extends Controller
{
    public function __construct(private readonly EarningsLedger $ledger) {}

    public function show(Request $request): EarningsAccountResource
    {
        /** @var User $rider */
        $rider = $request->user();

        return new EarningsAccountResource($this->ledger->account(EarningsOwner::Rider, $rider->id));
    }

    public function entries(Request $request): AnonymousResourceCollection
    {
        /** @var User $rider */
        $rider = $request->user();
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']])['per_page'] ?? 25;

        return EarningsEntryResource::collection(
            $this->ledger->account(EarningsOwner::Rider, $rider->id)->entries()->orderByDesc('id')->cursorPaginate((int) $perPage)
        );
    }
}

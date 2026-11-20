<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\Ledger\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The customer's own wallet and its history (spec 06 §6.4). Top-ups arrive with milestone 2.5. */
class WalletController extends Controller
{
    public function __construct(private readonly WalletLedger $ledger) {}

    public function show(Request $request): WalletResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WalletResource($this->ledger->wallet($user));
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']])['per_page'] ?? 25;

        return WalletTransactionResource::collection(
            $this->ledger->wallet($user)->transactions()->orderByDesc('id')->cursorPaginate((int) $perPage)
        );
    }
}

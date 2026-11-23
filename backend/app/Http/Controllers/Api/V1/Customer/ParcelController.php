<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ParcelRequest;
use App\Models\User;
use App\Services\Orders\ParcelInput;
use App\Services\Orders\ParcelQuoteBuilder;
use App\Services\Orders\QuoteStore;
use Illuminate\Http\JsonResponse;

/**
 * Sending a parcel (spec 09 §9.6): compare operators, then quote with one. The quote is placed
 * like any other with POST /me/orders.
 */
class ParcelController extends Controller
{
    public function __construct(private readonly ParcelQuoteBuilder $builder) {}

    public function options(ParcelRequest $request): JsonResponse
    {
        $this->enabled();

        return response()->json(['data' => $this->builder->options(ParcelInput::fromArray($request->validated()))]);
    }

    public function quote(ParcelRequest $request): JsonResponse
    {
        $this->enabled();
        /** @var User $customer */
        $customer = $request->user();
        $built = $this->builder->build($customer, ParcelInput::fromArray($request->validated()));

        return response()->json(['data' => QuoteStore::present(QuoteStore::save($customer, $built), $built)], 201);
    }

    private function enabled(): void
    {
        if (! config('wegodeliva.parcels.enabled')) {
            throw new ApiException('parcels_disabled', 403);
        }
    }
}

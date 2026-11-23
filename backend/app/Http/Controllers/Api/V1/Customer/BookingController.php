<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\BookingRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\BookingInput;
use App\Services\Orders\BookingQuoteBuilder;
use App\Services\Orders\Bookings;
use App\Services\Orders\QuoteStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Booking a service (spec 09 §9.9): quote a slot, then place it with POST /me/orders. After the
 * provider marks the job done, the customer confirms it or reports a problem.
 */
class BookingController extends Controller
{
    public function __construct(private readonly Bookings $bookings) {}

    public function quote(BookingRequest $request, BookingQuoteBuilder $builder): JsonResponse
    {
        if (! config('wegodeliva.bookings.enabled')) {
            throw new ApiException('bookings_disabled', 403);
        }
        /** @var User $customer */
        $customer = $request->user();
        $built = $builder->build($customer, BookingInput::fromArray($request->validated()));

        return response()->json(['data' => QuoteStore::present(QuoteStore::save($customer, $built), $built)], 201);
    }

    public function confirm(Request $request, string $code): OrderResource
    {
        return $this->present($this->bookings->confirmCompletion($this->own($request, $code), $this->customer($request)));
    }

    public function report(Request $request, string $code): OrderResource
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        return $this->present($this->bookings->dispute($this->own($request, $code), $this->customer($request), $reason));
    }

    private function own(Request $request, string $code): Order
    {
        return Order::query()->where('customer_id', $this->customer($request)->id)->where('code', strtoupper($code))->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function present(Order $order): OrderResource
    {
        return new OrderResource($order->load('vendor', 'items', 'fees', 'history'));
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

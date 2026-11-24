<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\PlaceOrderRequest;
use App\Http\Requests\Customer\QuoteRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\User;
use App\Services\Orders\Bookings;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderPlacer;
use App\Services\Orders\QuoteBuilder;
use App\Services\Orders\QuoteInput;
use App\Services\Orders\QuoteStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer checkout and orders (spec 04 §4.4, spec 09 §9.3–9.5). */
class OrderController extends Controller
{
    public function quote(QuoteRequest $request, QuoteBuilder $builder): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $built = $builder->build($customer, QuoteInput::fromArray($request->validated()));

        return response()->json(['data' => QuoteStore::present(QuoteStore::save($customer, $built), $built)], 201);
    }

    public function place(PlaceOrderRequest $request, OrderPlacer $placer): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $quote = OrderQuote::query()->where('uuid', $request->validated('quote_id'))->where('user_id', $customer->id)->first();
        if ($quote === null) {
            throw new ApiException('quote_not_found', 404);
        }

        [$order, $created] = $placer->place(
            $customer,
            $quote,
            PaymentMethod::from((string) $request->validated('payment_method')),
            $request->validated('note'),
            $request->idempotencyKey(),
        );

        return (new OrderResource($this->loaded($order)))->response()->setStatusCode($created ? 201 : 200);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $customer */
        $customer = $request->user();
        $validated = $request->validate([
            'state' => ['nullable', 'in:active,past'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $final = array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal()));

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('vendor', 'items')
            ->when(($validated['state'] ?? null) === 'active', fn (Builder $q) => $q->whereNotIn('status', $final))
            ->when(($validated['state'] ?? null) === 'past', fn (Builder $q) => $q->whereIn('status', $final))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 20));

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $code): OrderResource
    {
        return new OrderResource($this->loaded($this->own($request, $code)));
    }

    public function cancel(Request $request, string $code, OrderLifecycle $lifecycle, Bookings $bookings): OrderResource
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $order = $this->own($request, $code);
        if (! in_array($order->status->value, (array) config('wegodeliva.orders.customer_cancellable'), true)) {
            throw new ApiException('order_not_cancellable', 409, meta: ['status' => $order->status->value]);
        }

        /** @var User $customer */
        $customer = $request->user();
        if ($order->isBooking()) {
            $bookings->customerCancel($order, $customer, $reason ?? 'Cancelled by customer');
        } else {
            $lifecycle->cancel($order, ActorRole::Customer, $customer, $reason ?? 'Cancelled by customer', Fault::Customer);
        }

        return new OrderResource($this->loaded($order));
    }

    /**
     * The cart to order the same again (spec 09 "Reorder"): the order's items that are still
     * on sale, with options still offered, and what could not be added and why. The app loads
     * it into the cart and asks for a fresh quote; prices are today's.
     */
    public function reorder(Request $request, string $code): JsonResponse
    {
        $order = $this->own($request, $code)->load('vendor', 'items.product.optionGroups.options');
        if ($order->isParcel() || $order->isBooking()) {
            throw new ApiException('order_not_reorderable', 422);
        }

        $items = [];
        $unavailable = [];
        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product === null || ! $product->is_active || $product->trashed() || ($product->stock_qty !== null && $product->stock_qty < 1)) {
                $unavailable[] = ['name' => $item->product_name, 'reason' => 'unavailable'];

                continue;
            }
            $offered = $product->optionGroups->where('is_active', true)->flatMap(fn ($g) => $g->options->where('is_active', true))->pluck('uuid')->all();
            $wanted = array_column($item->options ?? [], 'option_id');
            $kept = array_values(array_intersect($wanted, $offered));
            if (count($kept) !== count($wanted)) {
                $unavailable[] = ['name' => $item->product_name, 'reason' => 'options_changed'];
            }
            $items[] = ['product_id' => $product->uuid, 'quantity' => $item->quantity, 'option_ids' => $kept];
        }

        return response()->json(['data' => [
            'vendor' => ['id' => $order->vendor->uuid, 'name' => $order->vendor->name, 'slug' => $order->vendor->slug, 'open_state' => $order->vendor->openState()->value],
            'fulfilment' => $order->fulfilment->value,
            'items' => $items,
            'unavailable' => $unavailable,
        ]]);
    }

    /** The caller's own order; anyone else's answers 404 (spec 14 §14.1). */
    private function own(Request $request, string $code): Order
    {
        /** @var User $customer */
        $customer = $request->user();

        return Order::query()->where('customer_id', $customer->id)->where('code', strtoupper($code))->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function loaded(Order $order): Order
    {
        return $order->load('vendor', 'items', 'fees', 'history');
    }
}

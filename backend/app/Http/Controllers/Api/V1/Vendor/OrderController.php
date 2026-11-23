<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\OrderStatus;
use App\Http\Resources\OrderOpsResource;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\OrderFulfilment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The store's order inbox and actions (spec 10 "Order inbox", "Order details"). Owner and staff. */
class OrderController extends VendorController
{
    /** Inbox tabs (spec 10): new, preparing, ready, out for delivery, done. */
    private const TABS = [
        'new' => ['placed'],
        'preparing' => ['accepted'],
        'ready' => ['ready'],
        'out' => ['picked_up'],
        'done' => ['delivered', 'cancelled', 'failed'],
        'scheduled' => ['scheduled'],
    ];

    public function __construct(private readonly OrderFulfilment $fulfilment) {}

    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);
        $validated = $request->validate([
            'tab' => ['nullable', 'in:'.implode(',', array_keys(self::TABS))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $statuses = isset($validated['tab']) ? self::TABS[$validated['tab']] : array_merge(...array_values(self::TABS));

        $orders = Order::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', $statuses)
            ->with('vendor', 'customer', 'rider', 'items')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return OrderOpsResource::collection($orders);
    }

    public function show(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);

        return $this->present($this->order($vendor, $code));
    }

    public function accept(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $minutes = (int) $request->validate(['prep_time_minutes' => ['required', 'integer', 'min:5', 'max:180']])['prep_time_minutes'];

        return $this->present($this->fulfilment->accept($this->order($vendor, $code), $this->user($request), $minutes));
    }

    /** Reject a new order or cancel an accepted one; both refund the customer in full. */
    public function reject(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return $this->present($this->fulfilment->vendorCancel($this->order($vendor, $code), $this->user($request), $reason));
    }

    public function ready(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);

        return $this->present($this->fulfilment->ready($this->order($vendor, $code), $this->user($request)));
    }

    public function handover(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $data = $request->validate([
            'handover_code' => ['required', 'string', 'size:4'],
            'collected_via' => ['nullable', 'in:cash,transfer'],
        ]);

        return $this->present($this->fulfilment->vendorHandover($this->order($vendor, $code), $this->user($request), $data['handover_code'], $data['collected_via'] ?? null));
    }

    private function order(Vendor $vendor, string $code): Order
    {
        return Order::query()->where('vendor_id', $vendor->id)->where('code', strtoupper($code))
            // Orders waiting for payment or review are not the vendor's to see yet.
            ->whereNotIn('status', [OrderStatus::AwaitingPayment->value])
            ->first() ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function present(Order $order): OrderOpsResource
    {
        return OrderOpsResource::for($order->load('vendor', 'customer', 'rider', 'items'), 'vendor');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

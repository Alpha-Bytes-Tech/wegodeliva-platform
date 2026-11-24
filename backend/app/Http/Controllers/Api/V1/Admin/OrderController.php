<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorRole;
use App\Enums\EarningsOwner;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderOpsResource;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Ledger\EarningsLedger;
use App\Services\Orders\Bookings;
use App\Services\Orders\OrderFulfilment;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\Refunds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Order operations for admins (spec 12 "Orders", spec 06 §6.6): view, assign riders, cancel or
 * fail with a fault, complete a delivery support has verified, and refund — fully or partly,
 * with any earnings reversal the fault calls for. Everything is audited.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderFulfilment $fulfilment,
        private readonly OrderLifecycle $lifecycle,
        private readonly Refunds $refunds,
        private readonly EarningsLedger $earnings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-orders');
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()))],
            'unassigned' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:12'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = Order::query()
            ->with('vendor', 'customer', 'rider', 'items', 'fees', 'history')
            ->when($validated['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when((bool) ($validated['unassigned'] ?? false), fn (Builder $q) => $q->whereNull('rider_id')->where('fulfilment', 'delivery'))
            ->when($validated['q'] ?? null, fn (Builder $q, string $code) => $q->where('code', strtoupper($code)))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return OrderOpsResource::collection($orders->through(fn (Order $o) => OrderOpsResource::for($o, 'admin')));
    }

    public function show(string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');

        return $this->present($this->order($code));
    }

    public function assign(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $riderId = (string) $request->validate(['rider_id' => ['required', 'uuid']])['rider_id'];
        $rider = User::query()->where('uuid', $riderId)->first() ?? throw (new ModelNotFoundException)->setModel(User::class);
        $order = $this->fulfilment->assign($this->order($code), $rider, $this->admin($request));
        $this->audit->record($this->admin($request), 'order.rider_assigned', 'order', $order->code, after: ['rider' => $rider->uuid]);

        return $this->present($order);
    }

    public function unassign(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $order = $this->order($code);
        $before = $order->rider?->uuid;
        $this->fulfilment->unassign($order, $this->admin($request), $reason);
        $this->audit->record($this->admin($request), 'order.rider_unassigned', 'order', $order->code, ['rider' => $before], ['reason' => $reason]);

        return $this->present($order);
    }

    /** Cancel any order that is not final, with the fault and an optional cancellation fee (spec 06 §6.6). */
    public function cancel(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'fault' => ['required', 'in:customer,vendor,rider,platform,none'],
            'cancellation_fee_kobo' => ['nullable', 'integer', 'min:0'],
        ]);
        $order = $this->order($code);
        $fee = $data['fault'] === 'customer' ? (int) ($data['cancellation_fee_kobo'] ?? 0) : 0;

        $this->lifecycle->cancel($order, ActorRole::Admin, $this->admin($request), $data['reason'], Fault::from($data['fault']), $fee);
        $this->audit->record($this->admin($request), 'order.cancelled', 'order', $order->code, after: ['reason' => $data['reason'], 'fault' => $data['fault'], 'cancellation_fee_kobo' => $fee]);

        return $this->present($order);
    }

    /** Support verified the customer by phone and completes the delivery without the code. */
    public function deliver(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'collected_via' => ['nullable', 'in:cash,transfer'],
        ]);
        $order = $this->fulfilment->adminDeliver($this->order($code), $this->admin($request), $data['reason'], $data['collected_via'] ?? null);
        $this->audit->record($this->admin($request), 'order.delivered_override', 'order', $order->code, after: ['reason' => $data['reason']]);

        return $this->present($order);
    }

    /** Settles a disputed booking: release pays the provider, refund cancels and refunds in full. */
    public function resolveDispute(Request $request, string $code, Bookings $bookings): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $data = $request->validate([
            'outcome' => ['required', 'in:release,refund'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $order = $bookings->resolve($this->order($code), $this->admin($request), $data['outcome'], $data['note']);
        $this->audit->record($this->admin($request), 'booking.dispute_resolved', 'order', $order->code, after: $data);

        return $this->present($order);
    }

    public function fail(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-orders');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'fault' => ['required', 'in:customer,vendor,rider,platform,none'],
        ]);
        $order = $this->order($code);
        $this->fulfilment->fail($order, $this->admin($request), ActorRole::Admin, $data['reason'], null, false);
        $order->forceFill(['fault' => Fault::from($data['fault'])])->save();
        $this->audit->record($this->admin($request), 'order.failed', 'order', $order->code, after: $data);

        return $this->present($order);
    }

    /**
     * Refunds part or all of a paid order, and reverses the vendor's or rider's earnings when
     * the fault is theirs (spec 06 §6.6). Keyed by the client's idempotency key.
     */
    public function refund(Request $request, string $code): OrderOpsResource
    {
        Gate::authorize('manage-money');
        $data = $request->validate([
            'amount_kobo' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'fault' => ['required', 'in:customer,vendor,rider,platform,none'],
            'reverse_vendor_kobo' => ['nullable', 'integer', 'min:0'],
            'reverse_rider_kobo' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $order = $this->order($code);
        $admin = $this->admin($request);
        $key = $data['idempotency_key'];

        DB::transaction(function () use ($order, $admin, $data, $key): void {
            $refund = $this->refunds->refund($order, (int) $data['amount_kobo'], $data['reason'], Fault::from($data['fault']), $admin, "admin-refund:{$key}");
            if (! $refund->wasRecentlyCreated) {
                return;
            }

            $vendorReversal = (int) ($data['reverse_vendor_kobo'] ?? 0);
            if ($vendorReversal > 0) {
                $this->earnings->reverse($this->earnings->account(EarningsOwner::Vendor, $order->vendor_id), $vendorReversal, "admin-refund:{$key}:vendor", $data['reason'], $order->id);
            }
            $riderReversal = (int) ($data['reverse_rider_kobo'] ?? 0);
            if ($riderReversal > 0 && $order->rider_id !== null) {
                $this->earnings->reverse($this->earnings->account(EarningsOwner::Rider, $order->rider_id), $riderReversal, "admin-refund:{$key}:rider", $data['reason'], $order->id);
            }

            $this->audit->record($admin, 'order.refunded', 'order', $order->code, after: $data);
        });

        return $this->present($order);
    }

    private function order(string $code): Order
    {
        return Order::query()->where('code', strtoupper($code))->first() ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function present(Order $order): OrderOpsResource
    {
        return OrderOpsResource::for($order->load('vendor', 'customer', 'rider', 'items', 'fees', 'history'), 'admin');
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}

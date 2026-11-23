<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\ActorRole;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderOpsResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderFulfilment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The rider's assigned jobs and delivery steps (spec 07 §7.3, spec 11). Offers and matching
 * arrive with the rider app (Phase 5); until then admins assign riders.
 */
class JobController extends Controller
{
    public function __construct(private readonly OrderFulfilment $fulfilment) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['state' => ['nullable', 'in:active,past'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $active = [OrderStatus::Accepted->value, OrderStatus::Ready->value, OrderStatus::PickedUp->value];

        $jobs = Order::query()
            ->where('rider_id', $this->rider($request)->id)
            ->when(($validated['state'] ?? 'active') === 'active', fn ($q) => $q->whereIn('status', $active), fn ($q) => $q->whereNotIn('status', $active))
            ->with('vendor', 'customer', 'rider', 'items')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return OrderOpsResource::collection($jobs->through(fn (Order $o) => OrderOpsResource::for($o, 'rider')));
    }

    public function show(Request $request, string $code): OrderOpsResource
    {
        return $this->present($this->job($request, $code));
    }

    public function pickedUp(Request $request, string $code): OrderOpsResource
    {
        return $this->present($this->fulfilment->pickedUp($this->job($request, $code), $this->rider($request)));
    }

    public function deliver(Request $request, string $code): OrderOpsResource
    {
        $data = $request->validate([
            'handover_code' => ['required', 'string', 'size:4'],
            'collected_via' => ['nullable', 'in:cash,transfer'],
        ]);

        return $this->present($this->fulfilment->riderDeliver($this->job($request, $code), $this->rider($request), $data['handover_code'], $data['collected_via'] ?? null));
    }

    public function fail(Request $request, string $code): OrderOpsResource
    {
        $order = $this->job($request, $code);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            // Uploaded to private storage first; signed upload URLs arrive with the rider app.
            'photo_path' => ['required', 'string', 'max:255', 'not_regex:#\.\.#', 'regex:#^orders/'.preg_quote($order->uuid, '#').'/[A-Za-z0-9._/-]+$#'],
            'customer_called' => ['required', 'accepted'],
        ]);

        return $this->present($this->fulfilment->fail($order, $this->rider($request), ActorRole::Rider, $data['reason'], $data['photo_path'], true));
    }

    /** Only the rider's own jobs exist for them (spec 14 §14.1: 404 otherwise). */
    private function job(Request $request, string $code): Order
    {
        return Order::query()->where('rider_id', $this->rider($request)->id)->where('code', strtoupper($code))->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function present(Order $order): OrderOpsResource
    {
        return OrderOpsResource::for($order->load('vendor', 'customer', 'rider', 'items'), 'rider');
    }

    private function rider(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

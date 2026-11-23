<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\ActorRole;
use App\Enums\OrderStatus;
use App\Enums\RiderType;
use App\Exceptions\ApiException;
use App\Http\Resources\OrderOpsResource;
use App\Http\Resources\ParcelRateResource;
use App\Models\Order;
use App\Models\PackageType;
use App\Models\ParcelRate;
use App\Models\RiderProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\OrderFulfilment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A parcel operator's rates per package type (spec 10 "Parcel rates"), its own riders, and
 * assigning them to its orders: parcel jobs go only to the operator's riders (owner decision
 * 2026-11-23). Staff can view and assign; only the owner changes prices.
 */
class ParcelController extends VendorController
{
    public function __construct(private readonly OrderFulfilment $fulfilment) {}

    public function rates(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->member($request, $vendor);
        $this->operator($vendor);

        return ParcelRateResource::collection(ParcelRate::query()->where('vendor_id', $vendor->id)->with('packageType')->orderBy('id')->get());
    }

    public function saveRate(Request $request, Vendor $vendor, PackageType $packageType): JsonResponse
    {
        $this->owner($request, $vendor);
        $this->operator($vendor);
        $data = $request->validate([
            'base_price_kobo' => ['required', 'integer', 'min:0', 'max:100000000'],
            'distance_rate_kobo' => ['required', 'integer', 'min:0', 'max:10000000'],
            'distance_per_km' => ['required', 'boolean'],
            'size_rate_kobo' => ['required', 'integer', 'min:0', 'max:10000000'],
            'size_per_kg' => ['required', 'boolean'],
            'extra_stop_fee_kobo' => ['required', 'integer', 'min:0', 'max:10000000'],
            'max_advance_booking_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'auto_dispatch' => ['sometimes', 'boolean'],
            'requires_dimensions' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rate = ParcelRate::query()->where('vendor_id', $vendor->id)->where('package_type_id', $packageType->id)->first() ?? new ParcelRate;
        $created = ! $rate->exists;
        $rate->forceFill(['vendor_id' => $vendor->id, 'package_type_id' => $packageType->id])->fill($data)->save();

        return (new ParcelRateResource($rate->load('packageType')))->response()->setStatusCode($created ? 201 : 200);
    }

    public function riders(Request $request, Vendor $vendor): JsonResponse
    {
        $this->member($request, $vendor);

        $riders = RiderProfile::query()
            ->where('vendor_id', $vendor->id)
            ->where('rider_type', RiderType::VendorOwned->value)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (RiderProfile $p) => [
                'id' => $p->user->uuid,
                'name' => $p->user->name,
                'phone' => $p->user->phone,
                'status' => $p->status,
                'is_online' => $p->is_online,
                'active_jobs' => Order::query()->where('rider_id', $p->user_id)->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Ready->value, OrderStatus::PickedUp->value])->count(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $riders]);
    }

    public function assign(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $riderId = (string) $request->validate(['rider_id' => ['required', 'uuid']])['rider_id'];

        $order = Order::query()->where('vendor_id', $vendor->id)->where('code', strtoupper($code))
            ->where('status', '!=', OrderStatus::AwaitingPayment->value)->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);
        $rider = User::query()->where('uuid', $riderId)
            ->whereHas('riderProfile', fn ($q) => $q->where('vendor_id', $vendor->id)->where('rider_type', RiderType::VendorOwned->value))
            ->first() ?? throw new ApiException('rider_not_eligible', 422);

        /** @var User $by */
        $by = $request->user();
        $this->fulfilment->assign($order, $rider, $by, ActorRole::Vendor);

        return OrderOpsResource::for($order->load('vendor', 'customer', 'rider', 'items'), 'vendor');
    }

    private function operator(Vendor $vendor): void
    {
        $vendor->loadMissing('vendorType');
        if ($vendor->vendorType->slug !== Order::TYPE_PARCEL) {
            throw new ApiException('not_parcel_operator', 409);
        }
    }
}

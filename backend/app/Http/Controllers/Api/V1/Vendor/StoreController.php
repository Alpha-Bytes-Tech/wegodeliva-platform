<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\OpenState;
use App\Http\Requests\Vendor\HoursRequest;
use App\Http\Requests\Vendor\StoreStatusRequest;
use App\Http\Requests\Vendor\UpdateStoreRequest;
use App\Http\Resources\VendorStoreResource;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** The stores a vendor-app user works at, their settings, status and hours (spec 10). */
class StoreController extends VendorController
{
    /** "My vendors" for the vendor switcher. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return VendorStoreResource::collection($user->vendors()->with('vendorType', 'hours')->orderBy('vendors.name')->get());
    }

    public function show(Request $request, Vendor $vendor): VendorStoreResource
    {
        $this->member($request, $vendor);

        return new VendorStoreResource($vendor->load('vendorType', 'hours', 'members', 'zones'));
    }

    public function update(UpdateStoreRequest $request, Vendor $vendor): VendorStoreResource
    {
        $this->owner($request, $vendor);
        $vendor->fill($request->validated())->save();

        return new VendorStoreResource($vendor->load('vendorType', 'hours'));
    }

    /** Open / busy / closed for a while, or back to the opening hours. */
    public function status(StoreStatusRequest $request, Vendor $vendor): VendorStoreResource
    {
        $this->member($request, $vendor);
        $state = $request->string('state')->toString();

        if ($state === 'schedule') {
            $vendor->manual_state = null;
            $vendor->manual_state_until = null;
        } else {
            $vendor->manual_state = OpenState::from($state);
            $minutes = $request->integer('minutes');
            $vendor->manual_state_until = $minutes > 0 ? now()->addMinutes($minutes) : null;
        }
        $vendor->save();

        return new VendorStoreResource($vendor->load('vendorType', 'hours'));
    }

    public function hours(HoursRequest $request, Vendor $vendor): VendorStoreResource
    {
        $this->member($request, $vendor);

        /** @var list<array{weekday: int, opens_at: string, closes_at: string}> $hours */
        $hours = $request->validated('hours');
        DB::transaction(function () use ($vendor, $hours): void {
            $vendor->hours()->delete();
            foreach ($hours as $period) {
                $vendor->hours()->create($period);
            }
        });

        return new VendorStoreResource($vendor->load('vendorType', 'hours'));
    }
}

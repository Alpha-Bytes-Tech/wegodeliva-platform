<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\User;
use App\Services\Catalogue\ZoneLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved addresses (spec 14 §14.2 "Addresses"). The city and zone are always worked
 * out from the pin, never taken from the client. Another user's address answers 404.
 */
class AddressController extends Controller
{
    public function __construct(private readonly ZoneLocator $locator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $this->user($request)->addresses()->with('city', 'zone')->orderByDesc('is_default')->orderByDesc('id')->get(),
        );
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $max = (int) config('wegodeliva.catalogue.max_addresses');
        if ($user->addresses()->count() >= $max) {
            throw new ApiException('address_limit_reached', 422, meta: ['max' => $max]);
        }

        $address = new Address($request->validated());
        $address->user()->associate($user);
        $this->save($address, $user);

        return (new AddressResource($address->load('city', 'zone')))->response()->setStatusCode(201);
    }

    public function update(AddressRequest $request, string $uuid): AddressResource
    {
        $user = $this->user($request);
        $address = $user->addresses()->where('uuid', $uuid)->firstOrFail();
        $address->fill($request->validated());
        $this->save($address, $user);

        return new AddressResource($address->load('city', 'zone'));
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $this->user($request)->addresses()->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->noContent();
    }

    private function save(Address $address, User $user): void
    {
        if ($address->isDirty(['lat', 'lng']) || ! $address->exists) {
            $zone = $this->locator->zoneAt((float) $address->lat, (float) $address->lng);
            $address->zone_id = $zone?->id;
            $address->city_id = $this->locator->cityAt((float) $address->lat, (float) $address->lng, $zone)?->id;
        }

        DB::transaction(function () use ($address, $user): void {
            // The first address becomes the default; setting a new default clears the old one.
            $isFirst = ! $address->exists && ! $user->addresses()->exists();
            if ($isFirst) {
                $address->is_default = true;
            }
            if ($address->is_default) {
                $user->addresses()->where('is_default', true)->when($address->exists, fn ($q) => $q->whereKeyNot($address->id))->update(['is_default' => false]);
            }
            $address->save();
        });
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

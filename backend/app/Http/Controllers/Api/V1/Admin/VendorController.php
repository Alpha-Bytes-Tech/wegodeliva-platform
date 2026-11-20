<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleName;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateVendorRequest;
use App\Http\Requests\Admin\VendorZonesRequest;
use App\Http\Resources\VendorStoreResource;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Models\Zone;
use App\Services\AuditLogger;
use App\Services\Catalogue\ZoneLocator;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Vendor management for operations staff (spec 12 "Vendors"): create, approve, suspend and set
 * delivery zones. Every change is written to the audit log.
 */
class VendorController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-vendors');
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending_approval,active,suspended'],
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $vendors = Vendor::query()
            ->with('vendorType', 'hours')
            ->when($validated['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($validated['q'] ?? null, fn (Builder $q, string $term) => $q->whereRaw('name ILIKE ?', ['%'.addcslashes($term, '\\%_').'%']))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return VendorStoreResource::collection($vendors);
    }

    public function show(Vendor $vendor): VendorStoreResource
    {
        Gate::authorize('manage-vendors');

        return new VendorStoreResource($vendor->load('vendorType', 'hours', 'members', 'zones'));
    }

    public function store(CreateVendorRequest $request, ZoneLocator $locator): JsonResponse
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();
        $data = $request->validated();

        $vendor = DB::transaction(function () use ($data, $locator): Vendor {
            $owner = $this->ownerFor($data['owner']);

            $vendor = new Vendor(collect($data)->only(['name', 'description', 'phone', 'email', 'address_line', 'lat', 'lng'])->all());
            $vendor->vendor_type_id = VendorType::query()->where('slug', $data['vendor_type'])->value('id');
            $vendor->slug = $this->uniqueSlug($data['name']);
            $zone = $locator->zoneAt((float) $data['lat'], (float) $data['lng']);
            $vendor->zone_id = $zone?->id;
            $vendor->city_id = $locator->cityAt((float) $data['lat'], (float) $data['lng'], $zone)?->id;
            $vendor->save();

            $vendor->members()->attach($owner->id, ['role' => VendorMemberRole::Owner->value]);
            $owner->assignRole(RoleName::VendorOwner);

            return $vendor;
        });

        $this->audit->record($admin, 'vendor.created', 'vendor', $vendor->uuid, after: ['name' => $vendor->name, 'owner' => $data['owner']['email']]);

        return (new VendorStoreResource($vendor->load('vendorType', 'members')))->response()->setStatusCode(201);
    }

    public function approve(Request $request, Vendor $vendor): VendorStoreResource
    {
        return $this->setStatus($request, $vendor, VendorStatus::Active, 'vendor.approved');
    }

    public function suspend(Request $request, Vendor $vendor): VendorStoreResource
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->setStatus($request, $vendor, VendorStatus::Suspended, 'vendor.suspended');
    }

    public function zones(VendorZonesRequest $request, Vendor $vendor): VendorStoreResource
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();

        /** @var list<array{zone_id: string, delivery_fee_kobo?: int|null}> $zones */
        $zones = $request->validated('zones');
        $ids = Zone::query()->whereIn('uuid', array_column($zones, 'zone_id'))->pluck('id', 'uuid');
        $before = $vendor->zones()->pluck('zones.uuid')->all();

        $vendor->zones()->sync(collect($zones)->mapWithKeys(fn (array $z) => [
            $ids[$z['zone_id']] => ['delivery_fee_kobo' => $z['delivery_fee_kobo'] ?? null],
        ])->all());

        $this->audit->record($admin, 'vendor.zones_updated', 'vendor', $vendor->uuid, ['zones' => $before], ['zones' => array_column($zones, 'zone_id')]);

        return new VendorStoreResource($vendor->load('vendorType', 'hours', 'zones'));
    }

    private function setStatus(Request $request, Vendor $vendor, VendorStatus $status, string $action): VendorStoreResource
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();

        if ($vendor->status === $status) {
            throw new ApiException('vendor_status_unchanged', 409);
        }

        $before = $vendor->status->value;
        $vendor->forceFill([
            'status' => $status,
            'approved_at' => $status === VendorStatus::Active ? ($vendor->approved_at ?? now()) : $vendor->approved_at,
        ])->save();

        $this->audit->record($admin, $action, 'vendor', $vendor->uuid, ['status' => $before], array_filter([
            'status' => $status->value,
            'reason' => $request->input('reason'),
        ]));

        return new VendorStoreResource($vendor->load('vendorType', 'hours'));
    }

    /** @param  array{email: string, name?: string|null, phone?: string|null}  $owner */
    private function ownerFor(array $owner): User
    {
        $email = User::normaliseEmail($owner['email']);
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        $missing = array_filter(['owner.name' => $owner['name'] ?? null, 'owner.phone' => $owner['phone'] ?? null], fn ($v) => $v === null || $v === '');
        if ($missing !== []) {
            throw new ApiException('validation_failed', 400, fields: array_map(fn () => ['Required when the owner has no account yet.'], $missing));
        }
        $phone = PhoneNumber::normalise((string) $owner['phone']);
        if (User::query()->where('phone', $phone)->exists()) {
            throw new ApiException('validation_failed', 400, fields: ['owner.phone' => ['This phone number is already used by another account.']]);
        }

        // The owner proves the email by signing in with a code; no password is set here.
        return User::query()->create([
            'name' => $owner['name'],
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'vendor';
        $slug = $base;
        $i = 2;
        while (Vendor::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}

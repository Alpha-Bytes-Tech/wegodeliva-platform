<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RiderType;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\RiderProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Riders for operations staff (spec 12 "Users/vendors/riders"). Until rider sign-up and
 * documents arrive (Phase 5–6), an admin turns an existing account into an approved rider.
 */
class RiderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-orders');
        $riders = RiderProfile::query()->with('user', 'vendor')->orderByDesc('id')->cursorPaginate(25);

        return response()->json([
            'data' => $riders->getCollection()->map(fn (RiderProfile $p) => $this->present($p))->all(),
            'meta' => ['next_cursor' => $riders->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'rider_type' => ['required', Rule::enum(RiderType::class)],
            'vendor_id' => ['nullable', 'required_if:rider_type,vendor_owned', 'prohibited_unless:rider_type,vendor_owned', 'uuid'],
            'vehicle_kind' => ['nullable', 'in:bicycle,motorbike,car,on_foot'],
            'plate_number' => ['nullable', 'string', 'max:16'],
        ]);
        $user = User::query()->where('uuid', $data['user_id'])->first() ?? throw (new ModelNotFoundException)->setModel(User::class);
        $vendor = isset($data['vendor_id']) ? (Vendor::query()->where('uuid', $data['vendor_id'])->first() ?? throw (new ModelNotFoundException)->setModel(Vendor::class)) : null;

        $profile = DB::transaction(function () use ($user, $vendor, $data): RiderProfile {
            $profile = RiderProfile::query()->where('user_id', $user->id)->first() ?? new RiderProfile;
            $profile->forceFill([
                'user_id' => $user->id,
                'rider_type' => RiderType::from($data['rider_type']),
                'vendor_id' => $vendor?->id,
                'vehicle_kind' => $data['vehicle_kind'] ?? 'motorbike',
                'plate_number' => $data['plate_number'] ?? null,
                'status' => 'active',
                'approved_at' => $profile->approved_at ?? now(),
            ])->save();
            $user->assignRole(RoleName::Rider);

            return $profile;
        });

        $this->audit->record($admin, 'rider.approved', 'rider', $user->uuid, after: ['rider_type' => $data['rider_type'], 'vendor' => $vendor?->uuid]);

        return response()->json(['data' => $this->present($profile->load('user', 'vendor'))], $profile->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function present(RiderProfile $profile): array
    {
        return [
            'id' => $profile->user->uuid,
            'name' => $profile->user->name,
            'phone' => $profile->user->phone,
            'rider_type' => $profile->rider_type->value,
            'vendor_id' => $profile->vendor?->uuid,
            'vehicle_kind' => $profile->vehicle_kind,
            'status' => $profile->status,
            'approved_at' => $profile->approved_at?->toIso8601String(),
        ];
    }
}

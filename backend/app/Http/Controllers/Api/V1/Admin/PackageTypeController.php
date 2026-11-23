<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageTypeResource;
use App\Models\PackageType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Parcel package types (spec 12 "Parcels"). Switched off rather than deleted; changes are audited. */
class PackageTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-vendors');

        return PackageTypeResource::collection(PackageType::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-vendors');
        $type = PackageType::query()->create($request->validate($this->rules('required')));
        $this->audit->record($this->admin($request), 'package_type.created', 'package_type', $type->uuid, after: $type->only($type->getFillable()));

        return (new PackageTypeResource($type))->response()->setStatusCode(201);
    }

    public function update(Request $request, PackageType $packageType): PackageTypeResource
    {
        Gate::authorize('manage-vendors');
        $before = $packageType->only($packageType->getFillable());
        $packageType->update($request->validate($this->rules('sometimes')));
        $this->audit->record($this->admin($request), 'package_type.updated', 'package_type', $packageType->uuid, $before, $packageType->only($packageType->getFillable()));

        return new PackageTypeResource($packageType);
    }

    /** @return array<string, mixed> */
    private function rules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'image_path' => ['nullable', 'string', 'max:255', 'regex:#^package-types/[A-Za-z0-9._/-]+$#', 'not_regex:#\.\.#'],
            'requires_stop_verification' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}

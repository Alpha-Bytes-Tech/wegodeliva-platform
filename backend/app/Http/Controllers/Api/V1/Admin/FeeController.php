<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeeRequest;
use App\Http\Resources\FeeResource;
use App\Models\Fee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Service fees (spec 12.5). Switched off rather than deleted; changes are audited. */
class FeeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-vendors');

        return FeeResource::collection(Fee::query()->orderBy('name')->get());
    }

    public function store(FeeRequest $request): JsonResponse
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();

        $fee = Fee::query()->create($request->validated());
        $this->audit->record($admin, 'fee.created', 'fee', $fee->uuid, after: $this->snapshot($fee));

        return (new FeeResource($fee))->response()->setStatusCode(201);
    }

    public function update(FeeRequest $request, Fee $fee): FeeResource
    {
        Gate::authorize('manage-vendors');
        /** @var User $admin */
        $admin = $request->user();

        $before = $this->snapshot($fee);
        $fee->update($request->validated());
        $this->audit->record($admin, 'fee.updated', 'fee', $fee->uuid, $before, $this->snapshot($fee));

        return new FeeResource($fee);
    }

    /** @return array<string, mixed> */
    private function snapshot(Fee $fee): array
    {
        return ['name' => $fee->name, 'type' => $fee->type->value, 'value' => $fee->value, 'beneficiary' => $fee->beneficiary->value, 'is_active' => $fee->is_active];
    }
}

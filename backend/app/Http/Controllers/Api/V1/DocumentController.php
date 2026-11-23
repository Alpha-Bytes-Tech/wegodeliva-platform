<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EarningsOwner;
use App\Enums\VendorMemberRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Documents;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Documents for approval: the store owner and the rider submit theirs; admins request, approve
 * and reject (spec 10 "Documents", spec 11, spec 12). Paths must point into the owner's own
 * folder of the private bucket.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    public function vendorIndex(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $this->storeOwner($request, $vendor);

        return $this->list(EarningsOwner::Vendor, $vendor->id);
    }

    public function vendorSubmit(Request $request, Vendor $vendor): JsonResponse
    {
        $this->storeOwner($request, $vendor);

        return $this->submit($request, EarningsOwner::Vendor, $vendor->id, "vendors/{$vendor->uuid}/documents/");
    }

    public function riderIndex(Request $request): AnonymousResourceCollection
    {
        return $this->list(EarningsOwner::Rider, $this->user($request)->id);
    }

    public function riderSubmit(Request $request): JsonResponse
    {
        $rider = $this->user($request);

        return $this->submit($request, EarningsOwner::Rider, $rider->id, "riders/{$rider->uuid}/documents/");
    }

    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-vendors');
        $status = $request->validate(['status' => ['nullable', 'in:requested,submitted,approved,rejected']])['status'] ?? null;

        return DocumentResource::collection(Document::query()->when($status, fn (Builder $q, string $s) => $q->where('status', $s))->orderByDesc('id')->cursorPaginate(25));
    }

    public function adminRequest(Request $request): JsonResponse
    {
        Gate::authorize('manage-vendors');
        $data = $request->validate([
            'owner_type' => ['required', 'in:vendor,rider'],
            'owner_id' => ['required', 'uuid'],
            'kind' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $type = EarningsOwner::from($data['owner_type']);
        if (! in_array($data['kind'], Documents::kinds($type), true)) {
            throw new ApiException('validation_failed', 400, fields: ['kind' => ['This kind of document is not used for that owner.']]);
        }
        $ownerId = $type === EarningsOwner::Vendor
            ? Vendor::query()->where('uuid', $data['owner_id'])->value('id')
            : User::query()->where('uuid', $data['owner_id'])->first()?->riderProfile()->value('user_id');
        if ($ownerId === null) {
            throw (new ModelNotFoundException)->setModel($type === EarningsOwner::Vendor ? Vendor::class : User::class);
        }

        return (new DocumentResource($this->documents->request($type, (int) $ownerId, $data['kind'], $this->user($request), $data['note'] ?? null)))->response()->setStatusCode(201);
    }

    public function adminReview(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('manage-vendors');
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:255']]);

        return new DocumentResource($this->documents->review($document, $this->user($request), $data['decision'] === 'approve', $data['note'] ?? null));
    }

    private function list(EarningsOwner $type, int $ownerId): AnonymousResourceCollection
    {
        return DocumentResource::collection(Document::query()->where('owner_type', $type->value)->where('owner_id', $ownerId)->orderByDesc('id')->get());
    }

    private function submit(Request $request, EarningsOwner $type, int $ownerId, string $folder): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Documents::kinds($type))],
            'file_path' => ['required', 'string', 'max:255', 'not_regex:#\.\.#', 'regex:#^'.preg_quote($folder, '#').'[A-Za-z0-9._/-]+$#'],
        ]);

        return (new DocumentResource($this->documents->submit($type, $ownerId, $data['kind'], $data['file_path'])))->response()->setStatusCode(201);
    }

    private function storeOwner(Request $request, Vendor $vendor): void
    {
        $role = $vendor->memberRole($this->user($request));
        if ($role === null) {
            throw (new ModelNotFoundException)->setModel(Vendor::class);
        }
        if ($role !== VendorMemberRole::Owner) {
            throw new AuthorizationException;
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

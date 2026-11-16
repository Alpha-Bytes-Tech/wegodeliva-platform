<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Audit log viewer (spec 12.11). Read-only; cursor pagination, max 50 per page. */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-audit-log');

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $entries = AdminAuditLog::query()
            ->with('actor')
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return AuditLogResource::collection($entries);
    }
}

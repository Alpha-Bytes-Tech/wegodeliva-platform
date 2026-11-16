<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/** Writes the append-only admin audit log (spec 03 §3.1, spec 12). */
final class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        ?User $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500),
        ]);
    }
}

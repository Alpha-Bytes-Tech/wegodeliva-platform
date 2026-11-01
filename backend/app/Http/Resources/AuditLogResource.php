<?php

namespace App\Http\Resources;

use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminAuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor ? ['uuid' => $this->actor->uuid, 'name' => $this->actor->name] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'ip' => $this->ip,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

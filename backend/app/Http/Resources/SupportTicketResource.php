<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /** @var array<string, string|null> photo path => signed URL, set by the controller */
    public array $photoUrls = [];

    public bool $forAdmin = false;

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'category' => $this->category,
            'message' => $this->message,
            'order_code' => $this->order?->code,
            'photos' => array_map(fn (string $p) => $this->photoUrls[$p] ?? null, $this->photo_paths),
            'status' => $this->status,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            $this->mergeWhen($this->forAdmin, fn () => [
                'user' => ['id' => $this->user->uuid, 'name' => $this->user->name, 'email' => $this->user->email, 'phone' => $this->user->phone],
            ]),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as shown publicly: the author's first name only.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public bool $forAdmin = false;

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'subject' => $this->subject_type,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'author' => strtok((string) $this->author?->name, ' ') ?: null,
            'created_at' => $this->created_at->toIso8601String(),
            $this->mergeWhen($this->forAdmin, fn () => ['is_visible' => $this->is_visible]),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Banner
 */
class BannerResource extends JsonResource
{
    /** Signed read URL for the image, set by the controller (one batch call per list). */
    public ?string $imageUrl = null;

    public bool $forAdmin = false;

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'placement' => $this->placement,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->imageUrl,
            'link' => $this->link,
            $this->mergeWhen($this->forAdmin, fn () => [
                'image_path' => $this->image_path,
                'vendor_type_id' => $this->vendor_type_id,
                'sort_order' => $this->sort_order,
                'is_active' => $this->is_active,
                'starts_at' => $this->starts_at?->toIso8601String(),
                'ends_at' => $this->ends_at?->toIso8601String(),
            ]),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Option;
use App\Models\OptionGroup;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionGroup */
class OptionGroupResource extends JsonResource
{
    /** Public views list active choices only; vendor views pass false to see every choice. */
    public bool $activeOptionsOnly = true;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $options = $this->options->filter(fn (Option $o) => ! $this->activeOptionsOnly || $o->is_active)->values();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'is_required' => $this->is_required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'options' => $options->map(fn (Option $o) => [
                'id' => $o->uuid,
                'name' => $o->name,
                'description' => $o->description,
                'price' => Money::of($o->price_kobo),
                'per_unit' => $o->per_unit,
                'sort_order' => $o->sort_order,
                'is_active' => $o->is_active,
            ])->all(),
        ];
    }
}

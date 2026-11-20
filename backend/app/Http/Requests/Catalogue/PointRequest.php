<?php

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\ApiRequest;

/** A point on the map; required lat/lng, or optional when `$optional` is set by the subclass. */
class PointRequest extends ApiRequest
{
    protected bool $optional = false;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->optional ? 'nullable' : 'required';

        return [
            'lat' => [$presence, 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => [$presence, 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array{0: float, 1: float}|null */
    public function point(): ?array
    {
        if ($this->input('lat') === null || $this->input('lng') === null) {
            return null;
        }

        return [(float) $this->input('lat'), (float) $this->input('lng')];
    }
}

<?php

namespace App\Http\Requests\Catalogue;

use App\Http\Requests\ApiRequest;

/** A point (and optional filters) for "vendors near me" and search (spec 14 §14.2 "Catalogue"). */
class NearbyRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'vendor_type' => ['nullable', 'string', 'exists:vendor_types,slug'],
            'category' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('wegodeliva.catalogue.max_page_size')],
        ];
    }

    public function lat(): float
    {
        return (float) $this->input('lat');
    }

    public function lng(): float
    {
        return (float) $this->input('lng');
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 20);
    }
}

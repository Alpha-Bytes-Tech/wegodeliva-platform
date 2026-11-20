<?php

namespace App\Services\Catalogue;

use App\Models\City;
use App\Models\Zone;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the delivery zone and city for a point ("do you deliver here?", spec 14 §14.2).
 * Zones match only when PostGIS is installed and a boundary has been drawn (admin, Phase 6);
 * campus zones win over the wider zones they sit in. The city falls back to the nearest active
 * city centre within `catalogue.city_radius_m`.
 */
class ZoneLocator
{
    private ?bool $hasPolygons = null;

    public function zoneAt(float $lat, float $lng): ?Zone
    {
        if (! $this->polygonsAvailable()) {
            return null;
        }

        return Zone::query()
            ->where('is_active', true)
            ->whereNotNull('polygon')
            ->whereRaw('ST_Covers(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$lng, $lat])
            ->orderByDesc('is_campus')
            ->orderBy('id')
            ->first();
    }

    public function cityAt(float $lat, float $lng, ?Zone $zone = null): ?City
    {
        if ($zone !== null) {
            return $zone->city;
        }

        $nearest = null;
        $best = (int) config('wegodeliva.catalogue.city_radius_m');
        foreach (City::query()->where('is_active', true)->whereNotNull('centre_lat')->get() as $city) {
            $distance = Geo::distanceMeters($lat, $lng, (float) $city->centre_lat, (float) $city->centre_lng);
            if ($distance <= $best) {
                $best = $distance;
                $nearest = $city;
            }
        }

        return $nearest;
    }

    private function polygonsAvailable(): bool
    {
        return $this->hasPolygons ??= DB::getDriverName() === 'pgsql' && Schema::hasColumn('zones', 'polygon');
    }
}

<?php

namespace App\Support;

/**
 * Straight-line distances. Launch uses straight-line distance × a road factor instead of a paid
 * maps API (owner decision 2026-11-20, spec 05 §5.2 fallback); road distance can replace it later.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6371000;

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * SQL for the straight-line distance in metres from a point to the row's lat/lng columns.
     * Bind the point as [lat, lat, lng] (in that order).
     */
    public static function distanceSql(string $latColumn = 'lat', string $lngColumn = 'lng'): string
    {
        return '('.self::EARTH_RADIUS_M.' * 2 * asin(sqrt('
            ."power(sin(radians({$latColumn} - ?) / 2), 2) + "
            ."cos(radians(?)) * cos(radians({$latColumn})) * power(sin(radians({$lngColumn} - ?) / 2), 2)"
            .')))';
    }
}

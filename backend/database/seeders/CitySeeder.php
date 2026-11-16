<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Launch cities and campus zones (spec 01 §1.2). Campus zones have no polygon yet: ops draw
 * the boundaries in the admin panel (Phase 6), so they are seeded inactive. City centres are
 * approximate and only used to centre maps.
 */
class CitySeeder extends Seeder
{
    public const CITIES = [
        ['name' => 'Lagos', 'state' => 'Lagos', 'lat' => '6.524400', 'lng' => '3.379200', 'campuses' => [
            'University of Lagos (UNILAG), Akoka',
            'Lagos State University (LASU), Ojo',
            'Yaba College of Technology (YABATECH)',
            'Lagos State University of Science and Technology (LASUSTECH), Ikorodu',
        ]],
        ['name' => 'Ibadan', 'state' => 'Oyo', 'lat' => '7.377500', 'lng' => '3.947000', 'campuses' => [
            'University of Ibadan (UI)',
        ]],
        ['name' => 'Abeokuta', 'state' => 'Ogun', 'lat' => '7.145200', 'lng' => '3.327700', 'campuses' => [
            'Federal University of Agriculture, Abeokuta (FUNAAB)',
        ]],
        ['name' => 'Ago-Iwoye', 'state' => 'Ogun', 'lat' => '6.940000', 'lng' => '3.920000', 'campuses' => [
            'Olabisi Onabanjo University (OOU), Ago-Iwoye',
        ]],
        ['name' => 'Ile-Ife', 'state' => 'Osun', 'lat' => '7.482400', 'lng' => '4.560300', 'campuses' => [
            'Obafemi Awolowo University (OAU), Ile-Ife',
        ]],
        ['name' => 'Benin City', 'state' => 'Edo', 'lat' => '6.335000', 'lng' => '5.603700', 'campuses' => [
            'University of Benin (UNIBEN)',
        ]],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $data) {
            $city = City::query()->updateOrCreate(
                ['slug' => Str::slug($data['name'])],
                [
                    'name' => $data['name'],
                    'state' => $data['state'],
                    'is_active' => true,
                    'centre_lat' => $data['lat'],
                    'centre_lng' => $data['lng'],
                ],
            );

            foreach ($data['campuses'] as $campus) {
                $zone = $city->zones()->firstOrNew(['slug' => Str::slug($campus)]);
                if (! $zone->exists) {
                    // Inactive until ops draw the boundary; never re-deactivate a live zone.
                    $zone->is_active = false;
                }
                $zone->fill(['name' => $campus, 'is_campus' => true])->save();
            }
        }
    }
}

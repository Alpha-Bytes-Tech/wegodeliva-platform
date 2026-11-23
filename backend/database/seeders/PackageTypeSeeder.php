<?php

namespace Database\Seeders;

use App\Models\PackageType;
use Illuminate\Database\Seeder;

/** Starter parcel package types (spec 03 §3.4); admins can edit or add more. */
class PackageTypeSeeder extends Seeder
{
    private const TYPES = [
        ['name' => 'Document', 'description' => 'Envelopes and papers.', 'requires_stop_verification' => true],
        ['name' => 'Small parcel', 'description' => 'Fits in a backpack, up to 5 kg.', 'requires_stop_verification' => false],
        ['name' => 'Medium box', 'description' => 'Fits on a bike carrier, up to 15 kg.', 'requires_stop_verification' => false],
        ['name' => 'Large box', 'description' => 'Bulky items, up to 30 kg.', 'requires_stop_verification' => false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $order => $type) {
            $existing = PackageType::query()->where('name', $type['name'])->first();
            if ($existing === null) {
                PackageType::query()->create($type + ['sort_order' => $order + 1, 'is_active' => true]);
            }
        }
    }
}

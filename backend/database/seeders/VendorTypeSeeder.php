<?php

namespace Database\Seeders;

use App\Models\VendorType;
use Illuminate\Database\Seeder;

/** The six verticals in scope at launch (spec 01 §1.3). */
class VendorTypeSeeder extends Seeder
{
    public const TYPES = [
        ['slug' => 'food', 'name' => 'Food', 'description' => 'Restaurants, kitchens and snacks.'],
        ['slug' => 'groceries', 'name' => 'Groceries', 'description' => 'Supermarkets and provisions.'],
        ['slug' => 'pharmacy', 'name' => 'Pharmacy', 'description' => 'Medicines, health and personal care.'],
        ['slug' => 'fashion', 'name' => 'Fashion', 'description' => 'Clothes, shoes and general shopping.'],
        ['slug' => 'parcels', 'name' => 'Parcels', 'description' => 'Send packages across town.'],
        ['slug' => 'services', 'name' => 'Services', 'description' => 'Book laundry, beauty, repairs and more.'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $order => $type) {
            VendorType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name'], 'description' => $type['description'], 'sort_order' => $order + 1, 'is_active' => true],
            );
        }
    }
}

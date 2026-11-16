<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\VendorType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The 14 service categories (spec 01 §1.3.1). VAT treatment defaults to standard for services
 * (spec 05 §5.6); educational services are left unset until the accountant confirms.
 */
class ServiceCategorySeeder extends Seeder
{
    public const CATEGORIES = [
        ['name' => 'Fitness and personal training', 'description' => 'Personal trainer sessions, group classes, yoga'],
        ['name' => 'Travel and tours', 'description' => 'Tour packages, airport pickup, visa assistance'],
        ['name' => 'Fashion', 'description' => 'Tailoring, alterations, styling'],
        ['name' => 'Venues', 'description' => 'Halls, event spaces, meeting rooms'],
        ['name' => 'Printing and signage', 'description' => 'Printing, branding, banners, signage'],
        ['name' => 'Photography and video', 'description' => 'Shoots, event coverage, editing'],
        ['name' => 'Automotive', 'description' => 'Car wash, mechanic, servicing'],
        ['name' => 'Health and beauty', 'description' => 'Hair, barbing, makeup, nails, spa'],
        ['name' => 'Laundry', 'description' => 'Wash and fold, dry cleaning, ironing'],
        ['name' => 'Party, catering and events', 'description' => 'Catering, event planning, decoration, rentals'],
        ['name' => 'DJ and entertainment', 'description' => 'DJs, MCs, live bands, performers'],
        ['name' => 'Computer and IT', 'description' => 'Repairs, setup, software installation'],
        ['name' => 'Janitorial and fumigation', 'description' => 'Cleaning, deep cleaning, fumigation and pest control'],
        ['name' => 'Childcare and educational', 'description' => 'Babysitting, lessons, tutoring', 'vat_treatment' => null],
    ];

    public function run(): void
    {
        $this->call(VendorTypeSeeder::class);
        $services = VendorType::query()->where('slug', 'services')->firstOrFail();

        foreach (self::CATEGORIES as $order => $category) {
            Category::query()->updateOrCreate(
                ['vendor_type_id' => $services->id, 'slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'vat_treatment' => array_key_exists('vat_treatment', $category) ? $category['vat_treatment'] : 'standard',
                    'sort_order' => $order + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}

<?php

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\City;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorType;
use App\Models\Zone;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

it('creates every role in the migration', function () {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::cases())->map->value->sort()->values()->all());
});

it('seeds reference data idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(VendorType::query()->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['food', 'groceries', 'pharmacy', 'fashion', 'parcels', 'services']);

    $services = VendorType::query()->where('slug', 'services')->sole();
    expect(Category::query()->where('vendor_type_id', $services->id)->count())->toBe(14)
        ->and(Category::query()->where('slug', 'laundry')->value('vat_treatment'))->toBe('standard')
        ->and(Category::query()->where('slug', 'childcare-and-educational')->value('vat_treatment'))->toBeNull();

    expect(City::query()->count())->toBe(6)
        ->and(Zone::query()->where('is_campus', true)->count())->toBe(9)
        ->and(Zone::query()->where('is_active', true)->count())->toBe(0);

    $lagos = City::query()->where('slug', 'lagos')->sole();
    expect($lagos->zones()->count())->toBe(4);
});

it('creates the super admin only from environment settings', function () {
    $this->seed(SuperAdminSeeder::class);
    expect(User::query()->count())->toBe(0);

    config(['wegodeliva.super_admin' => ['name' => 'Owner', 'email' => 'Owner@WeGoDeliva.com', 'phone' => '08030000000', 'password' => 'a-long-enough-password']]);
    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    $admin = User::query()->sole();
    expect($admin->email)->toBe('owner@wegodeliva.com')
        ->and($admin->hasRole(RoleName::SuperAdmin))->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-enough-password', (string) $admin->password))->toBeTrue();
});

it('refuses a short super admin password or bad phone', function (array $override) {
    config(['wegodeliva.super_admin' => array_merge(['name' => 'Owner', 'email' => 'o@x.com', 'phone' => '08030000000', 'password' => 'a-long-enough-password'], $override)]);

    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->count())->toBe(0);
})->with([[['password' => 'short']], [['phone' => '12345']]]);

it('stores zone polygons as PostGIS geography when available', function () {
    $hasPostgis = DB::scalar("SELECT count(*) FROM pg_extension WHERE extname = 'postgis'") > 0;

    expect(Schema::hasColumn('zones', 'polygon'))->toBe($hasPostgis);
    if ($hasPostgis) {
        expect(DB::scalar("SELECT udt_name FROM information_schema.columns WHERE table_name = 'zones' AND column_name = 'polygon'"))->toBe('geography');
    }
});

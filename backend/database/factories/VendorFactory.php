<?php

namespace Database\Factories;

use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Active vendors near Yaba, Lagos, open around the clock unless a state says otherwise.
 *
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'address_line' => fake()->streetAddress(),
            'lat' => '6.515000',
            'lng' => '3.385000',
            'delivery_radius_m' => 5000,
            'accepts_delivery' => true,
            'status' => VendorStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Vendor $vendor): void {
            $vendor->slug ??= Str::slug($vendor->name).'-'.Str::lower(Str::random(5));
            $vendor->vendor_type_id ??= VendorType::query()->where('slug', 'food')->value('id')
                ?? VendorType::query()->create(['slug' => 'food', 'name' => 'Food', 'sort_order' => 1, 'is_active' => true])->id;
        })->afterCreating(function (Vendor $vendor): void {
            // Open all day, every day; tests about opening hours replace these rows.
            foreach (range(0, 6) as $day) {
                $vendor->hours()->create(['weekday' => $day, 'opens_at' => '00:00', 'closes_at' => '00:00']);
            }
        });
    }

    public function status(VendorStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function at(float $lat, float $lng): static
    {
        return $this->state(['lat' => number_format($lat, 6, '.', ''), 'lng' => number_format($lng, 6, '.', '')]);
    }

    public function ofType(string $slug): static
    {
        return $this->afterMaking(function (Vendor $vendor) use ($slug): void {
            $vendor->vendor_type_id = VendorType::query()->where('slug', $slug)->value('id')
                ?? VendorType::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true])->id;
        });
    }

    /** Adds a member with the given role. */
    public function withMember(User $user, VendorMemberRole $role = VendorMemberRole::Owner): static
    {
        return $this->afterCreating(fn (Vendor $vendor) => $vendor->members()->attach($user->id, ['role' => $role->value]));
    }
}

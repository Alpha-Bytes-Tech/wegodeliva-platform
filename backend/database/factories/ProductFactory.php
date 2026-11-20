<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'price_kobo' => 250000,
            'is_active' => true,
        ];
    }
}

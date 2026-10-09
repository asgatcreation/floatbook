<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Create outlets inside a tenant context, e.g. tenancy()->runAs($business, fn () => Outlet::factory()->create()).
 *
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Ketu', 'Ojota', 'Mile 12', 'Yaba', 'Ikeja', 'Oshodi', 'Surulere', 'Agege', 'Ikorodu', 'Lekki', 'Ajah', 'Festac'])
            .' '.fake()->randomElement(['Main', 'Market', 'Junction', 'Plaza']);

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'address' => fake()->streetAddress().', Lagos',
            'is_active' => true,
        ];
    }
}

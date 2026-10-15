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
        $name = fake()->randomElement(['Ketu', 'Ojota', 'Mile 12', 'Yaba', 'Ikeja', 'Oshodi', 'Surulere', 'Agege', 'Ikorodu', 'Lekki', 'Ajah', 'Festac'])
            .' '.fake()->randomElement(['Main', 'Market', 'Junction', 'Plaza']);

        // Names are unique per business, and tests often name an outlet by hand
        // (e.g. "Ketu Main"), so step past any name the business already uses.
        $base = $name;
        for ($n = 2; Outlet::query()->where('name', $name)->exists(); $n++) {
            $name = "{$base} {$n}";
        }

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'address' => fake()->streetAddress().', Lagos',
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' POS',
            'timezone' => 'Africa/Lagos',
            'closing_time' => '20:00:00',
            'require_two_factor' => false,
        ];
    }

    /**
     * Create the business with an owner (a new verified user unless one is given).
     */
    public function withOwner(?User $owner = null): static
    {
        return $this->afterCreating(function (Business $business) use ($owner): void {
            $owner ??= User::factory()->create();

            app(CurrentBusiness::class)->runAs($business, fn () => Membership::query()->create([
                'user_id' => $owner->id,
                'role' => Role::Owner,
            ]));

            $owner->forceFill(['current_business_id' => $business->id])->save();
        });
    }
}

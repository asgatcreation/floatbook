<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create memberships inside a tenant context.
 *
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::Agent,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(['role' => $role]);
    }
}

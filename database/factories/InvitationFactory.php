<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Create invitations inside a tenant context.
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Accountant,
            'outlet_ids' => [],
            'token_hash' => Invitation::hashToken(Str::random(48)),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ];
    }

    /**
     * Use a known plain token so a test can open the invitation link.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => Invitation::hashToken($token)]);
    }
}

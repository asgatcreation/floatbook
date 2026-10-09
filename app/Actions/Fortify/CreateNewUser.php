<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Business sign-up: creates the owner's account, the business and the owner membership together.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'business_name' => ['required', 'string', 'min:2', 'max:80'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ], [], [
            'business_name' => 'business name',
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $business = Business::create(['name' => trim($input['business_name'])]);

            return $this->tenancy->runAs($business, function () use ($user, $business): User {
                $membership = Membership::create(['user_id' => $user->id, 'role' => Role::Owner]);

                $user->forceFill(['current_business_id' => $business->id])->save();

                $this->audit->record('business.created', $business, after: ['name' => $business->name], actor: $user);
                $this->audit->record('membership.created', $membership, after: ['role' => Role::Owner->value, 'user' => $user->email], actor: $user);

                return $user;
            });
        });
    }
}

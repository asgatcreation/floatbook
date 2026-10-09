<?php

use App\Enums\Role;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against PostgreSQL (the floatbook_test database) and are
| wrapped in a transaction that is rolled back after each test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests start real worker processes, which can only see committed data,
// so these tests commit and clean up by truncating tables instead of rolling back.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function tenancy(): CurrentBusiness
{
    return app(CurrentBusiness::class);
}

/**
 * A business with a verified owner. Returns [business, owner user, owner membership].
 *
 * @return array{0: Business, 1: User, 2: Membership}
 */
function businessWithOwner(array $attributes = []): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->withOwner($owner)->create($attributes);
    $membership = tenancy()->runAs($business, fn () => Membership::query()->where('user_id', $owner->id)->firstOrFail());

    return [$business, $owner, $membership];
}

/**
 * Add a member with the given role (and outlets, for supervisors and agents) to a business.
 *
 * @param  list<Outlet>  $outlets
 */
function addMember(Business $business, Role $role, array $outlets = [], ?User $user = null): Membership
{
    $user ??= User::factory()->create();

    return tenancy()->runAs($business, function () use ($business, $role, $outlets, $user) {
        $membership = Membership::query()->create(['user_id' => $user->id, 'role' => $role]);
        $membership->syncOutlets(array_map(fn (Outlet $o) => $o->id, $outlets));
        $user->forceFill(['current_business_id' => $business->id])->save();

        return $membership->load('user', 'outlets');
    });
}

function addOutlet(Business $business, array $attributes = []): Outlet
{
    return tenancy()->runAs($business, fn () => Outlet::factory()->create($attributes));
}

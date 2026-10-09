<?php

use App\Enums\Role;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('members can visit the dashboard', function () {
    [, $owner] = businessWithOwner();

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
});

test('people without an active membership are sent to the no-business page', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(route('no-business'));
});

test('the shared tenant props describe the current business and role', function () {
    [$business] = businessWithOwner(['name' => 'Ade & Sons POS']);
    $outlet = addOutlet($business, ['name' => 'Ketu Main']);
    $agent = addMember($business, Role::Agent, [$outlet]);

    $this->actingAs($agent->user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('tenant.business.name', 'Ade & Sons POS')
            ->where('tenant.membership.role', 'agent')
            ->where('tenant.membership.permissions', ['record_transactions'])
            ->where('tenant.membership.outlets.0.name', 'Ketu Main'));
});

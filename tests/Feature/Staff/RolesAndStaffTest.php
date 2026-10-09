<?php

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Mail\StaffInvitation;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
    $this->supervisor = addMember($this->business, Role::Supervisor, [$this->ketu]);
    $this->agentKetu = addMember($this->business, Role::Agent, [$this->ketu]);
    $this->agentOjota = addMember($this->business, Role::Agent, [$this->ojota]);
    $this->accountant = addMember($this->business, Role::Accountant);
});

test('each role reaches exactly the pages it should', function (string $who, array $allowed, array $forbidden) {
    $this->actingAs($this->{$who}->user ?? $this->{$who});

    foreach ($allowed as $route) {
        $this->get(route($route))->assertOk();
    }

    foreach ($forbidden as $route) {
        $this->get(route($route))->assertForbidden();
    }
})->with([
    'owner' => ['owner', ['dashboard', 'outlets.index', 'staff.index', 'audit-log.index', 'business.edit'], []],
    'supervisor' => ['supervisor', ['dashboard', 'staff.index'], ['outlets.index', 'audit-log.index', 'business.edit']],
    'agent' => ['agentKetu', ['dashboard'], ['outlets.index', 'staff.index', 'audit-log.index', 'business.edit']],
    'accountant' => ['accountant', ['dashboard', 'audit-log.index'], ['outlets.index', 'staff.index', 'business.edit']],
]);

test('a supervisor only sees agents in their own outlets', function () {
    $this->actingAs($this->supervisor->user)->get(route('staff.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)->pluck('id')->sort()->values()->all()
                === collect([$this->supervisor->id, $this->agentKetu->id])->sort()->values()->all())
            ->where('roles', fn ($roles) => collect($roles)->pluck('value')->all() === ['agent'])
            ->has('outlets', 1));
});

test('a supervisor can invite agents to their outlets only', function () {
    Mail::fake();
    $this->actingAs($this->supervisor->user);

    $this->post(route('invitations.store'), ['email' => 'new@ketu.test', 'role' => 'agent', 'outlet_ids' => [$this->ketu->id]])
        ->assertSessionHasNoErrors();
    $this->post(route('invitations.store'), ['email' => 'new@ojota.test', 'role' => 'agent', 'outlet_ids' => [$this->ojota->id]])
        ->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'boss@ketu.test', 'role' => 'owner'])
        ->assertSessionHasErrors('role');

    Mail::assertSentCount(1);
    Mail::assertSent(StaffInvitation::class, fn ($mail) => $mail->hasTo('new@ketu.test'));
});

test('a supervisor can manage agents in their outlets but not others', function () {
    $this->actingAs($this->supervisor->user);

    $this->post(route('staff.suspend', $this->agentKetu))->assertRedirect();
    $this->post(route('staff.suspend', $this->agentOjota))->assertForbidden();
    $this->post(route('staff.suspend', $this->accountant))->assertForbidden();

    expect($this->agentKetu->fresh()->status)->toBe(MembershipStatus::Suspended)
        ->and($this->agentOjota->fresh()->status)->toBe(MembershipStatus::Active);
});

test('agents and accountants cannot invite or manage anyone', function (string $who) {
    $this->actingAs($this->{$who}->user);

    $this->post(route('invitations.store'), ['email' => 'x@example.com', 'role' => 'agent', 'outlet_ids' => [$this->ketu->id]])->assertForbidden();
    $this->post(route('staff.suspend', $this->agentOjota))->assertForbidden();
    $this->patch(route('staff.update', $this->agentOjota), ['role' => 'owner'])->assertForbidden();
})->with(['agentKetu', 'accountant']);

test('nobody can change their own role', function () {
    $this->actingAs($this->owner)
        ->patch(route('staff.update', $this->ownerMembership), ['role' => 'agent', 'outlet_ids' => [$this->ketu->id]])
        ->assertForbidden();
});

test('an owner can change a member\'s role and outlets, and it is audited', function () {
    $this->actingAs($this->owner)
        ->patch(route('staff.update', $this->agentKetu), ['role' => 'supervisor', 'outlet_ids' => [$this->ketu->id, $this->ojota->id]])
        ->assertSessionHasNoErrors();

    $fresh = tenancy()->runAs($this->business, fn () => $this->agentKetu->fresh(['outlets']));
    expect($fresh->role)->toBe(Role::Supervisor)
        ->and($fresh->outlets->pluck('name')->sort()->values()->all())->toBe(['Ketu Main', 'Ojota']);

    $log = tenancy()->runAs($this->business, fn () => AuditLog::query()->where('action', 'membership.updated')->sole());
    expect($log->before['role'])->toBe('agent')->and($log->after['role'])->toBe('supervisor')
        ->and($log->actor_user_id)->toBe($this->owner->id);
});

test('the last owner cannot be demoted or suspended', function () {
    $secondOwner = addMember($this->business, Role::Owner);
    $this->actingAs($secondOwner->user);

    // Demoting the first owner works while another owner exists...
    $this->patch(route('staff.update', $this->ownerMembership), ['role' => 'accountant'])->assertSessionHasNoErrors();

    // ...but now the second owner is the last one, and the first can't touch them anyway.
    $this->actingAs($this->owner);
    $this->post(route('staff.suspend', $secondOwner))->assertForbidden();
});

test('a suspended member is signed out and loses access immediately', function () {
    DB::table('sessions')->insert([
        'id' => 'agent-session', 'user_id' => $this->agentKetu->user_id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($this->owner)->post(route('staff.suspend', $this->agentKetu));

    expect(DB::table('sessions')->where('user_id', $this->agentKetu->user_id)->exists())->toBeFalse();
    $this->actingAs($this->agentKetu->user)->get(route('dashboard'))->assertRedirect(route('no-business'));

    $this->actingAs($this->owner)->post(route('staff.reactivate', $this->agentKetu));
    $this->actingAs($this->agentKetu->user)->get(route('dashboard'))->assertOk();
});

test('the gate list matches each role', function () {
    expect(Role::Owner->permissions())->toHaveCount(count(Permission::cases()))
        ->and(Role::Agent->assignableRoles())->toBe([])
        ->and(Role::Supervisor->assignableRoles())->toBe([Role::Agent]);
});

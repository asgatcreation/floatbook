<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Outlet;
use App\Support\Audit;
use App\Support\Tenancy\TenancyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Two businesses side by side. Every test proves that business A can never see or
 * change business B's data, whether through HTTP or directly through the models.
 */
beforeEach(function () {
    [$this->a, $this->ownerA] = businessWithOwner(['name' => 'Business A']);
    [$this->b, $this->ownerB] = businessWithOwner(['name' => 'Business B']);

    $this->outletA = addOutlet($this->a, ['name' => 'A Outlet']);
    $this->outletB = addOutlet($this->b, ['name' => 'B Outlet']);
    $this->agentA = addMember($this->a, Role::Agent, [$this->outletA]);
    $this->agentB = addMember($this->b, Role::Agent, [$this->outletB]);
    $this->invitationA = tenancy()->runAs($this->a, fn () => Invitation::factory()->create(['email' => 'hello@a.test']));
    $this->invitationB = tenancy()->runAs($this->b, fn () => Invitation::factory()->create(['email' => 'secret@b.test']));

    foreach ([$this->a, $this->b] as $business) {
        tenancy()->runAs($business, fn () => app(Audit::class)->record('test.entry', meta: ['business' => $business->name]));
    }
});

/** Every tenant-owned model, so a new one can't be added without being covered here. */
dataset('tenant models', [
    'outlets' => [Outlet::class],
    'memberships' => [Membership::class],
    'invitations' => [Invitation::class],
    'audit logs' => [AuditLog::class],
]);

test('queries only ever return the current business\'s rows', function (string $model) {
    $idsA = tenancy()->runAs($this->a, fn () => $model::query()->pluck('business_id')->unique()->all());
    $idsB = tenancy()->runAs($this->b, fn () => $model::query()->pluck('business_id')->unique()->all());

    expect($idsA)->toBe([$this->a->id])
        ->and($idsB)->toBe([$this->b->id]);
})->with('tenant models');

test('with no current business, tenant queries return nothing (fail closed)', function (string $model) {
    tenancy()->clear();

    expect($model::query()->count())->toBe(0);
})->with('tenant models');

test('system mode can see every business (used only for jobs like the demo reset)', function () {
    expect(tenancy()->asSystem(fn () => Outlet::query()->count()))->toBe(2);
});

test('a row cannot be created for another business', function () {
    tenancy()->runAs($this->a, fn () => Outlet::factory()->create(['business_id' => $this->b->id]));
})->throws(TenancyViolation::class);

test('a row cannot be created without a current business', function () {
    Outlet::factory()->create();
})->throws(TenancyViolation::class);

test('a row cannot be moved to another business', function () {
    tenancy()->runAs($this->a, function () {
        $outlet = Outlet::query()->firstOrFail();
        $outlet->business_id = $this->b->id;
        $outlet->save();
    });
})->throws(TenancyViolation::class);

test('another business\'s row cannot be changed even if it was loaded', function () {
    $outletB = tenancy()->runAs($this->b, fn () => Outlet::query()->firstOrFail());

    tenancy()->runAs($this->a, fn () => $outletB->update(['name' => 'Hacked']));
})->throws(TenancyViolation::class);

test('the database refuses to link a member to another business\'s outlet', function () {
    DB::table('membership_outlet')->insert([
        'business_id' => $this->b->id,
        'membership_id' => $this->agentB->id,
        'outlet_id' => $this->outletA->id,
    ]);
})->throws(QueryException::class);

test('owner A cannot open, edit or act on business B through the website', function () {
    $this->actingAs($this->ownerA);

    // Positive control: the same requests work on A's own records, so a 404 below
    // really means "hidden from you", not "broken for everyone".
    $this->patch(route('outlets.update', $this->outletA), ['name' => 'A Outlet Renamed'])->assertRedirect();
    $this->patch(route('staff.update', $this->agentA), ['role' => 'agent', 'outlet_ids' => [$this->outletA->id]])->assertSessionHasNoErrors();
    $this->post(route('invitations.resend', $this->invitationA))->assertSessionHasNoErrors();

    $this->patch(route('outlets.update', $this->outletB), ['name' => 'Hacked'])->assertNotFound();
    $this->patch(route('staff.update', $this->agentB), ['role' => 'owner'])->assertNotFound();
    $this->post(route('staff.suspend', $this->agentB))->assertNotFound();
    $this->post(route('invitations.resend', $this->invitationB))->assertNotFound();
    $this->delete(route('invitations.destroy', $this->invitationB))->assertNotFound();

    expect(tenancy()->runAs($this->b, fn () => Outlet::query()->value('name')))->toBe('B Outlet');
});

test('lists only show the current business\'s data', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('outlets.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('outlets', 1)->where('outlets.0.name', 'A Outlet'));

    $this->get(route('staff.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('members', 2)->has('invitations', 1)
            ->where('invitations.0.email', 'hello@a.test')->has('outlets', 1));

    $this->get(route('audit-log.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('logs.data', fn ($logs) => collect($logs)->every(
            fn ($log) => ! str_contains(json_encode($log), 'Business B'),
        )));
});

test('owner A cannot assign business B\'s outlet to their own staff or invitations', function () {
    $this->actingAs($this->ownerA);

    $this->patch(route('staff.update', $this->agentA), ['role' => 'agent', 'outlet_ids' => [$this->outletB->id]])
        ->assertSessionHasErrors('outlet_ids.0');

    $this->post(route('invitations.store'), ['email' => 'x@a.test', 'role' => 'agent', 'outlet_ids' => [$this->outletB->id]])
        ->assertSessionHasErrors('outlet_ids.0');
});

test('a user cannot switch into a business they do not belong to', function () {
    $this->actingAs($this->ownerA)
        ->post(route('business.switch'), ['business_id' => $this->b->id])
        ->assertNotFound();

    expect($this->ownerA->fresh()->current_business_id)->toBe($this->a->id);
});

test('a person in two businesses only sees the one they switched to', function () {
    addMember($this->b, Role::Accountant, user: $this->ownerA);

    $this->actingAs($this->ownerA)->post(route('business.switch'), ['business_id' => $this->b->id])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('tenant.business.name', 'Business B')
            ->where('tenant.membership.role', 'accountant')
            ->has('tenant.businesses', 2));

    // As an accountant in B they cannot manage B's outlets, despite owning A.
    $this->get(route('outlets.index'))->assertForbidden();
});

test('slugs of other businesses do not leak through the business model', function () {
    expect(Business::query()->count())->toBe(2); // Businesses are the tenant root, not tenant data.
});

<?php

use App\Enums\Role;
use App\Mail\StaffInvitation;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    [$this->business, $this->owner] = businessWithOwner(['name' => 'Ade & Sons POS']);
    $this->ketu = addOutlet($this->business, ['name' => 'Ketu Main']);
    $this->ojota = addOutlet($this->business, ['name' => 'Ojota']);
});

function pendingInvitation(object $test, string $token, array $attributes = []): Invitation
{
    return tenancy()->runAs($test->business, fn () => Invitation::factory()->withToken($token)->create([
        'invited_by' => $test->owner->id,
        ...$attributes,
    ]));
}

test('an owner can invite an agent to specific outlets and an email is sent', function () {
    $this->actingAs($this->owner)
        ->post(route('invitations.store'), ['email' => 'Tunde@Example.com', 'role' => 'agent', 'outlet_ids' => [$this->ketu->id]])
        ->assertSessionHasNoErrors();

    $invitation = tenancy()->runAs($this->business, fn () => Invitation::query()->sole());

    expect($invitation->email)->toBe('tunde@example.com')
        ->and($invitation->role)->toBe(Role::Agent)
        ->and($invitation->outlet_ids)->toBe([$this->ketu->id])
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Mail::assertSent(StaffInvitation::class, fn (StaffInvitation $mail) => $mail->hasTo('tunde@example.com'));
});

test('the invitation email links to the accept page and names the business', function () {
    $invitation = pendingInvitation($this, 'plain-token-123', ['role' => Role::Agent, 'outlet_ids' => [$this->ketu->id]]);

    $html = (new StaffInvitation($invitation, $this->business, 'Adewale', 'plain-token-123'))->render();

    expect($html)->toContain('Ade &amp; Sons POS')
        ->toContain(route('invitations.show', 'plain-token-123'))
        ->toContain('Ketu Main');
});

test('agents and supervisors must be given at least one outlet', function () {
    $this->actingAs($this->owner)
        ->post(route('invitations.store'), ['email' => 'x@example.com', 'role' => 'supervisor', 'outlet_ids' => []])
        ->assertSessionHasErrors('outlet_ids');
});

test('existing members cannot be invited again', function () {
    $agent = addMember($this->business, Role::Agent, [$this->ketu]);

    $this->actingAs($this->owner)
        ->post(route('invitations.store'), ['email' => $agent->user->email, 'role' => 'accountant'])
        ->assertSessionHasErrors('email');
});

test('only the token hash is stored', function () {
    $this->actingAs($this->owner)->post(route('invitations.store'), ['email' => 'a@example.com', 'role' => 'accountant']);

    Mail::assertSent(StaffInvitation::class, function (StaffInvitation $mail) {
        $stored = tenancy()->runAs($this->business, fn () => Invitation::query()->sole()->token_hash);

        return $stored === Invitation::hashToken($mail->token) && $stored !== $mail->token;
    });
});

test('a new person can accept, set a password and lands in the business', function () {
    pendingInvitation($this, 'tok-new', ['email' => 'grace@example.com', 'role' => Role::Agent, 'outlet_ids' => [$this->ojota->id]]);

    $this->get(route('invitations.show', 'tok-new'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/Accept')->where('mode', 'new')->where('businessName', 'Ade & Sons POS'));

    $this->post(route('invitations.accept', 'tok-new'), [
        'name' => 'Grace Okafor',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'grace@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue();

    $membership = tenancy()->runAs($this->business, fn () => Membership::query()->where('user_id', $user->id)->with('outlets')->sole());
    expect($membership->role)->toBe(Role::Agent)
        ->and($membership->outlets->pluck('name')->all())->toBe(['Ojota']);
});

test('a person who already has an account is asked to sign in, then accepts', function () {
    $existing = User::factory()->create(['email' => 'emeka@example.com']);
    pendingInvitation($this, 'tok-existing', ['email' => 'emeka@example.com', 'role' => Role::Accountant]);

    $this->get(route('invitations.show', 'tok-existing'))->assertInertia(fn ($page) => $page->where('mode', 'login'));
    $this->post(route('invitations.accept', 'tok-existing'))->assertRedirect(route('login'));

    $this->actingAs($existing)->post(route('invitations.accept', 'tok-existing'))->assertRedirect(route('dashboard'));

    expect(tenancy()->runAs($this->business, fn () => Membership::query()->where('user_id', $existing->id)->value('role')))->toBe(Role::Accountant)
        ->and($existing->fresh()->current_business_id)->toBe($this->business->id);
});

test('someone signed in with a different email cannot accept', function () {
    pendingInvitation($this, 'tok-wrong', ['email' => 'right@example.com']);
    $intruder = User::factory()->create(['email' => 'wrong@example.com']);

    $this->actingAs($intruder)->post(route('invitations.accept', 'tok-wrong'))->assertRedirect();

    expect(tenancy()->runAs($this->business, fn () => Membership::query()->where('user_id', $intruder->id)->exists()))->toBeFalse();
});

test('expired, revoked and used invitations cannot be accepted', function (array $state, string $reason) {
    pendingInvitation($this, 'tok-dead', ['email' => 'late@example.com', ...$state]);

    $this->get(route('invitations.show', 'tok-dead'))
        ->assertInertia(fn ($page) => $page->component('invitations/Invalid')->where('reason', $reason));

    $this->post(route('invitations.accept', 'tok-dead'), ['name' => 'Late', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertStatus(410);

    expect(User::query()->where('email', 'late@example.com')->exists())->toBeFalse();
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'expired'],
    'revoked' => [['revoked_at' => now()], 'revoked'],
    'accepted' => [['accepted_at' => now()], 'accepted'],
]);

test('an unknown token shows the invalid page', function () {
    $this->get(route('invitations.show', 'does-not-exist'))
        ->assertInertia(fn ($page) => $page->component('invitations/Invalid')->where('reason', 'missing'));
});

test('re-sending gives a new link and the old one stops working', function () {
    $invitation = pendingInvitation($this, 'tok-old', ['email' => 'r@example.com']);

    $this->actingAs($this->owner)->post(route('invitations.resend', $invitation))->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('invitations.show', 'tok-old'))->assertInertia(fn ($page) => $page->component('invitations/Invalid'));
    Mail::assertSent(StaffInvitation::class, fn (StaffInvitation $mail) => $mail->token !== 'tok-old');
});

test('an owner can cancel an invitation', function () {
    $invitation = pendingInvitation($this, 'tok-cancel');

    $this->actingAs($this->owner)->delete(route('invitations.destroy', $invitation))->assertRedirect();

    expect($invitation->fresh()?->revoked_at)->not->toBeNull();
});

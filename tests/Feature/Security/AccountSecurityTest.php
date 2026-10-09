<?php

use App\Enums\Role;
use App\Http\Controllers\Auth\LockController;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    [$this->business, $this->owner, $this->ownerMembership] = businessWithOwner();
    $this->outlet = addOutlet($this->business);
    $this->agent = addMember($this->business, Role::Agent, [$this->outlet]);
});

function givePin(object $membership, string $pin = '2580'): void
{
    $membership->forceFill(['pin_hash' => Hash::make($pin)])->saveQuietly();
}

// --- Owner-required two-factor authentication ---------------------------------------

test('when 2FA is required, members without it can only reach the security page', function () {
    $this->business->forceFill(['require_two_factor' => true])->save();
    $accountant = addMember($this->business, Role::Accountant);

    $this->actingAs($accountant->user)->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('members who have 2FA, and agents (who use a PIN), are not blocked', function () {
    $this->business->forceFill(['require_two_factor' => true])->save();
    $secured = addMember($this->business, Role::Accountant, user: User::factory()->withTwoFactor()->create());

    $this->actingAs($secured->user)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->agent->user)->get(route('dashboard'))->assertOk();
});

test('only an owner can turn the 2FA requirement on, and it is audited', function () {
    $payload = ['name' => 'Renamed POS', 'timezone' => 'Africa/Lagos', 'closing_time' => '21:00', 'require_two_factor' => true, 'idle_lock_minutes' => 15];

    $this->actingAs($this->agent->user)->patch(route('business.update'), $payload)->assertForbidden();
    $this->actingAs($this->owner)->patch(route('business.update'), $payload)->assertSessionHasNoErrors();

    $business = $this->business->fresh();
    expect($business->require_two_factor)->toBeTrue()
        ->and($business->closing_time)->toBe('21:00:00')
        ->and($business->settings['idle_lock_minutes'])->toBe(15);

    $log = tenancy()->runAs($this->business, fn () => AuditLog::query()->where('action', 'business.settings_updated')->sole());
    expect($log->after['require_two_factor'])->toBeTrue();
});

// --- PIN and screen lock --------------------------------------------------------------

test('a member can set a PIN after confirming their password', function () {
    $this->actingAs($this->agent->user)
        ->put(route('pin.update'), ['current_password' => 'password', 'pin' => '2580', 'pin_confirmation' => '2580'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('2580', $this->agent->fresh()->pin_hash))->toBeTrue();
});

test('weak or malformed PINs are refused', function (string $pin) {
    $this->actingAs($this->agent->user)
        ->put(route('pin.update'), ['current_password' => 'password', 'pin' => $pin, 'pin_confirmation' => $pin])
        ->assertSessionHasErrors('pin');
})->with(['1234', '0000', '12', '1234567', 'abcd']);

test('a locked device only shows the unlock screen until the right PIN is entered', function () {
    givePin($this->agent);
    $this->actingAs($this->agent->user);

    $this->post(route('lock'))->assertRedirect(route('unlock.show'));
    $this->get(route('dashboard'))->assertRedirect(route('unlock.show'));
    $this->get(route('unlock.show'))->assertInertia(fn ($page) => $page->component('auth/Unlock')->where('usesPin', true));

    $this->post(route('unlock.store'), ['secret' => '9999'])->assertSessionHasErrors('secret');
    $this->post(route('unlock.store'), ['secret' => '2580'])->assertRedirect();
    $this->get(route('dashboard'))->assertOk();
});

test('an agent with a PIN is locked automatically after being idle', function () {
    givePin($this->agent);
    $this->actingAs($this->agent->user)->get(route('dashboard'))->assertOk();

    $this->travel(11)->minutes();

    $this->get(route('dashboard'))->assertRedirect(route('unlock.show'));
});

test('five wrong PINs sign the person out completely', function () {
    givePin($this->agent);
    $this->actingAs($this->agent->user)->post(route('lock'));

    foreach (range(1, LockController::MAX_PIN_ATTEMPTS - 1) as $ignored) {
        $this->post(route('unlock.store'), ['secret' => '1111'])->assertSessionHasErrors('secret');
    }

    $this->post(route('unlock.store'), ['secret' => '1111'])->assertRedirect(route('login'));
    $this->assertGuest();
});

test('without a PIN, the password unlocks the screen', function () {
    $this->actingAs($this->owner)->post(route('lock'));

    $this->get(route('unlock.show'))->assertInertia(fn ($page) => $page->where('usesPin', false));
    $this->post(route('unlock.store'), ['secret' => 'password'])->assertRedirect();
    $this->get(route('dashboard'))->assertOk();
});

// --- Sessions / devices -------------------------------------------------------------------

test('a user can see their signed-in devices and sign the others out', function () {
    DB::table('sessions')->insert([
        ['id' => 'phone', 'user_id' => $this->owner->id, 'ip_address' => '10.0.0.2', 'payload' => '', 'last_activity' => now()->getTimestamp(),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'],
    ]);

    $this->actingAs($this->owner)->get(route('sessions.index'))
        ->assertInertia(fn ($page) => $page->where('sessions.0.device', 'Chrome on Android'));

    $this->delete(route('sessions.destroy-others'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->delete(route('sessions.destroy-others'), ['password' => 'password'])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'phone')->exists())->toBeFalse();
});

// --- Audit log ------------------------------------------------------------------------------

test('the audit log cannot be edited or deleted, even with raw SQL', function () {
    $id = tenancy()->runAs($this->business, fn () => app(Audit::class)->record('test.entry')->id);

    expect(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('audit_logs')->where('id', $id)->delete())->toThrow(QueryException::class);
});

test('the audit log never stores secrets', function () {
    $this->actingAs($this->agent->user)
        ->put(route('pin.update'), ['current_password' => 'password', 'pin' => '2580', 'pin_confirmation' => '2580']);

    $all = DB::table('audit_logs')->get()->toJson();
    expect($all)->not->toContain('2580')->not->toContain('$2y$');
});

test('signing in is recorded in the audit log', function () {
    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password']);

    $actions = tenancy()->runAs($this->business, fn () => AuditLog::query()->pluck('action')->all());
    expect($actions)->toContain('user.signed_in');
});

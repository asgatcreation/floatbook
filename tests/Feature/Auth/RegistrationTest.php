<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Membership;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

test('the sign-up screen can be rendered', function () {
    $this->get(route('register'))->assertOk();
});

test('signing up creates the business, the owner membership and sends a verification code', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'business_name' => 'Ade & Sons POS',
        'name' => 'Adewale Adeyemi',
        'email' => 'ade@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'ade@example.com')->firstOrFail();
    $business = Business::query()->where('name', 'Ade & Sons POS')->firstOrFail();

    expect($user->current_business_id)->toBe($business->id)
        ->and($business->slug)->toBe('ade-sons-pos');

    $membership = tenancy()->runAs($business, fn () => Membership::query()->sole());
    expect($membership->user_id)->toBe($user->id)
        ->and($membership->role)->toBe(Role::Owner);

    Notification::assertSentTo($user, EmailVerificationCode::class);

    $actions = tenancy()->runAs($business, fn () => AuditLog::query()->pluck('action')->all());
    expect($actions)->toContain('business.created', 'membership.created');
});

test('a business name is required', function () {
    $this->post(route('register.store'), [
        'name' => 'Ade',
        'email' => 'ade@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('business_name');

    $this->assertGuest();
    expect(Business::query()->count())->toBe(0);
});

test('two businesses with the same name get different slugs', function () {
    Notification::fake();

    foreach (['a@example.com', 'b@example.com'] as $email) {
        $this->post(route('register.store'), [
            'business_name' => 'Mile 12 POS',
            'name' => 'Owner',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        auth()->logout();
    }

    expect(Business::query()->pluck('slug')->unique())->toHaveCount(2);
});

// A signed-up account is created before the code is emailed. If the email provider
// refuses the send, the account exists but the person is shown an error and never
// reaches the screen that asks for the code — stranded with an account they can't use.
test('signing up still completes when the email provider fails', function () {
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Brevo refused the key'));

    $response = $this->post(route('register.store'), [
        'business_name' => 'Ketu Cash Point',
        'name' => 'Sola Adeyemi',
        'email' => 'sola@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'sola@example.com')->firstOrFail();

    // The code is stored before it is sent, so "Send a new code" works once mail is fixed.
    expect(OneTimeCode::query()->where('user_id', $user->id)
        ->where('purpose', OneTimeCode::PURPOSE_VERIFY_EMAIL)->exists())->toBeTrue();

    // And the person lands on the screen that asks for the code, not on an error.
    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

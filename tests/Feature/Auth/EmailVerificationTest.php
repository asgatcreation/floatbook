<?php

use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Support\OneTimeCodes;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function unverifiedOwner(): User
{
    [, $owner] = businessWithOwner();
    $owner->forceFill(['email_verified_at' => null])->save();

    return $owner;
}

test('the verification screen can be rendered', function () {
    $this->actingAs(unverifiedOwner())->get(route('verification.notice'))->assertOk();
});

test('unverified users are sent to the verification screen', function () {
    $this->actingAs(unverifiedOwner())->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('the emailed code verifies the address', function () {
    Event::fake([Verified::class]);
    $user = unverifiedOwner();
    $code = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('a wrong code is rejected', function () {
    $user = unverifiedOwner();
    $code = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->actingAs($user)
        ->post(route('verification.code'), ['code' => $wrong])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired code is rejected', function () {
    $user = unverifiedOwner();
    $code = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);

    $this->travel(OneTimeCodes::VALID_MINUTES + 1)->minutes();

    $this->actingAs($user)->post(route('verification.code'), ['code' => $code])->assertSessionHasErrors('code');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('after five wrong guesses even the right code no longer works', function () {
    $user = unverifiedOwner();
    $code = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, OneTimeCodes::MAX_ATTEMPTS) as $ignored) {
        $this->actingAs($user)->post(route('verification.code'), ['code' => $wrong]);
    }

    $this->actingAs($user)->post(route('verification.code'), ['code' => $code])->assertSessionHasErrors('code');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('requesting a new code makes the old one stop working', function () {
    Notification::fake();
    $user = unverifiedOwner();
    $old = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);

    $this->actingAs($user)->post(route('verification.send'));

    Notification::assertSentTo($user, EmailVerificationCode::class);
    $this->actingAs($user)->post(route('verification.code'), ['code' => $old])->assertSessionHasErrors('code');
});

test('codes are stored hashed, never in plain text', function () {
    $user = unverifiedOwner();
    $code = app(OneTimeCodes::class)->issue($user, OneTimeCode::PURPOSE_VERIFY_EMAIL);

    $stored = OneTimeCode::query()->where('user_id', $user->id)->sole();
    expect($stored->code_hash)->not->toContain($code)->and(strlen($stored->code_hash))->toBe(64);
});

test('the verification email shows the code in the body only', function () {
    $user = unverifiedOwner();
    $mail = (new EmailVerificationCode('482913'))->toMail($user);

    expect($mail->subject)->not->toContain('482913')
        ->and(view($mail->view[0], $mail->viewData)->render())->toContain('482913');
});

test('verified users skip the verification screen', function () {
    [, $owner] = businessWithOwner();

    $this->actingAs($owner)->get(route('verification.notice'))->assertRedirect(route('dashboard', absolute: false));
});

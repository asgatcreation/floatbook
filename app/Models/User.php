<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Notifications\EmailVerificationCode;
use App\Support\OneTimeCodes;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Throwable;

/**
 * A person who can sign in. A user can belong to several businesses through memberships.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $current_business_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * All of the user's memberships across businesses. This deliberately bypasses the
     * tenant scope: it is how we work out which businesses a user may switch to.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', MembershipStatus::Active);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function currentBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'current_business_id');
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Email verification uses a 6-digit code (shown only inside the email) instead of a signed link.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = app(OneTimeCodes::class)->issue($this, OneTimeCode::PURPOSE_VERIFY_EMAIL);

        // The code is saved before it is sent, so "Send a new code" still works even if
        // the email provider is down. Signing up is never failed over a send: that would
        // leave someone with an account they cannot reach and an error page to look at.
        try {
            $this->notify(new EmailVerificationCode($code));
        } catch (Throwable $e) {
            Log::error('Could not send the email verification code.', [
                'user_id' => $this->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
